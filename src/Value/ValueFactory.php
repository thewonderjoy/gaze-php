<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Value;

use Symfony\Component\VarDumper\Cloner\Stub;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use TheWonderJoy\Gaze\Config;

/**
 * Converts PHP values to RFC-0001 §5 Value nodes.
 *
 * Scalars and small arrays of scalars take a direct path. Everything else goes through
 * Symfony's VarCloner, so casters (Doctrine, Carbon, Laravel, user casters) decide what an
 * object exposes, and then through ValueDumper.
 *
 * Limits (Gaze::configure(['limits' => [...]])): depth 8, 200 items per collection,
 * 5,000 nodes per value, 16 KiB per string; truncation is marked with "cut".
 */
final class ValueFactory
{
    public const MAX_SAFE_INT = 9007199254740991;

    private static ?VarCloner $cloner = null;

    /** @var array{depth:int, items:int, nodes:int, string:int}|null */
    private static ?array $limits = null;

    /** @var array{string?:int, nodes?:int} limits advertised by the app */
    private static array $server = [];

    /** @return array<string, mixed> */
    public static function from(mixed $value): array
    {
        try {
            if ($value === null || is_scalar($value)) {
                return self::scalar($value);
            }
            $limits = self::$limits ??= self::limits();
            if (is_array($value)) {
                $budget = $limits['nodes'] > 0 ? $limits['nodes'] : PHP_INT_MAX;
                $plain = self::plainArray($value, 1, $limits, $budget);
                if ($plain !== null) {
                    return $plain;
                }
            }

            return self::cloned($value, $limits);
        } catch (\Throwable $e) {
            return ['k' => 'const', 'v' => 'gaze: value could not be converted (' . $e::class . ': ' . $e->getMessage() . ')'];
        }
    }

    /**
     * JSON-like data (decoded JSON, config arrays): arrays, stdClass and scalars only.
     * stdClass becomes a map, so {} and [] stay distinguishable.
     *
     * @return array<string, mixed>
     */
    public static function fromPlain(mixed $value, int $depth = 1): array
    {
        $limits = self::$limits ??= self::limits();
        if ($value instanceof \stdClass) {
            $value = get_object_vars($value);
            $assoc = true;
        } elseif (is_array($value)) {
            $assoc = !array_is_list($value);
        } else {
            return is_object($value) || is_resource($value) ? self::from($value) : self::scalar($value);
        }
        $count = count($value);
        $node = $assoc ? ['k' => 'map', 'count' => $count, 'entries' => []] : ['k' => 'list', 'count' => $count, 'items' => []];
        if ($depth > $limits['depth']) {
            if ($count > 0) {
                $node['cut'] = $count;
            }

            return $node;
        }
        $i = 0;
        foreach ($value as $k => $v) {
            if ($i++ >= $limits['items']) {
                $node['cut'] = $count - $limits['items'];
                break;
            }
            $child = self::fromPlain($v, $depth + 1);
            if ($assoc) {
                $node['entries'][] = [$k, $child];
            } else {
                $node['items'][] = $child;
            }
        }

        return $node;
    }

    /** @return array<string, mixed> */
    public static function scalar(mixed $value): array
    {
        return match (true) {
            $value === null => ['k' => 'null'],
            is_string($value) => self::string($value),
            is_int($value) => self::int($value),
            is_float($value) => self::float($value),
            is_bool($value) => ['k' => 'bool', 'v' => $value],
            default => self::from($value),
        };
    }

    /** @return array<string, mixed> */
    public static function int(int $value): array
    {
        // JSON numbers lose precision beyond 2^53-1 in JS and in serde_json's f64 path.
        return ($value > self::MAX_SAFE_INT || $value < -self::MAX_SAFE_INT)
            ? ['k' => 'int', 'v' => (string) $value]
            : ['k' => 'int', 'v' => $value];
    }

    /** @return array<string, mixed> */
    public static function float(float $value): array
    {
        if (is_nan($value)) {
            return ['k' => 'float', 'v' => 'NaN'];
        }
        if (is_infinite($value)) {
            return ['k' => 'float', 'v' => $value > 0 ? 'INF' : '-INF'];
        }
        if ($value === 0.0 && (string) $value === '-0') {
            return ['k' => 'float', 'v' => '-0'];
        }

        return ['k' => 'float', 'v' => $value];
    }

    /** @return array<string, mixed> */
    public static function string(string $value): array
    {
        $max = (self::$limits ??= self::limits())['string'];
        if (!preg_match('//u', $value)) {
            $len = strlen($value);
            $node = ['k' => 'str', 'v' => base64_encode($max >= 0 && $len > $max ? substr($value, 0, $max) : $value), 'enc' => 'b64'];
            if ($max >= 0 && $len > $max) {
                $node['cut'] = $len - $max;
                $node['len'] = $len;
            }

            return $node;
        }
        if ($max < 0 || strlen($value) <= $max) {
            return ['k' => 'str', 'v' => $value];
        }
        $len = mb_strlen($value, 'UTF-8');
        if ($len <= $max) {
            return ['k' => 'str', 'v' => $value];
        }

        return ['k' => 'str', 'v' => mb_substr($value, 0, $max, 'UTF-8'), 'cut' => $len - $max, 'len' => $len];
    }

    /** Drops cached limits and the cloner (after Gaze::configure()). */
    public static function reset(bool $serverLimits = false): void
    {
        self::$cloner = null;
        self::$limits = null;
        if ($serverLimits) {
            self::$server = [];
        }
    }

    /**
     * Lowers the limits to what the app advertised in GET /v1/hello (never raises them).
     *
     * @param array<string, mixed> $limits
     */
    public static function applyServerLimits(array $limits): void
    {
        $server = [];
        if (isset($limits["max_string_chars"]) && is_int($limits["max_string_chars"]) && $limits["max_string_chars"] > 0) {
            $server["string"] = $limits["max_string_chars"];
        }
        if (isset($limits["max_value_nodes"]) && is_int($limits["max_value_nodes"]) && $limits["max_value_nodes"] > 0) {
            $server["nodes"] = $limits["max_value_nodes"];
        }
        if ($server !== self::$server) {
            self::$server = $server;
            self::$limits = null;
            self::$cloner = null;
        }
    }

    /** @internal caster: tags enums so the walker emits {k:"const"} */
    public static function castEnum(\UnitEnum $enum, array $a, Stub $stub): array
    {
        $stub->attr['gaze.enum'] = $enum::class . '::' . $enum->name;
        $stub->attr['gaze.cls'] = $enum::class;
        if ($enum instanceof \BackedEnum) {
            $stub->attr['gaze.value'] = $enum->value;
        }

        return $a;
    }

    /** @internal caster: tags dates so the walker emits {k:"date"} */
    public static function castDate(\DateTimeInterface $date, array $a, Stub $stub): array
    {
        $stub->attr['gaze.date'] = $date->format($date->format('u') === '000000' ? 'Y-m-d\TH:i:sP' : 'Y-m-d\TH:i:s.uP');
        $stub->attr['gaze.tz'] = $date->getTimezone()->getName();
        $stub->attr['gaze.cls'] = $date::class;

        return $a;
    }

    /** @internal caster: tags closures so the walker emits {k:"fn"} */
    public static function castClosure(\Closure $closure, array $a, Stub $stub): array
    {
        $stub->attr['gaze.fn'] = true;

        return $a;
    }

    /**
     * @param array{depth:int, items:int, nodes:int, string:int} $limits
     * @return array<string, mixed>
     */
    private static function cloned(mixed $value, array $limits): array
    {
        $cloner = self::$cloner ??= self::makeCloner($limits);
        $dumper = new ValueDumper($limits['items']);
        $cloner->cloneVar($value)->withMaxDepth($limits['depth'])->dump($dumper);

        return $dumper->root ?? ['k' => 'null'];
    }

    /**
     * Arrays that hold only scalars and nested arrays of scalars: no cloner needed.
     * Returns null as soon as anything else (objects, resources, too many nodes) shows up.
     *
     * @param array<array-key, mixed> $value
     * @param array{depth:int, items:int, nodes:int, string:int} $limits
     * @return array<string, mixed>|null
     */
    private static function plainArray(array $value, int $depth, array $limits, int &$budget): ?array
    {
        $count = count($value);
        if (($budget -= min($count, $limits['items'])) < 0) {
            return null;
        }
        $list = array_is_list($value);
        $node = $list ? ['k' => 'list', 'count' => $count, 'items' => []] : ['k' => 'map', 'count' => $count, 'entries' => []];
        if ($count === 0) {
            return $node;
        }
        if ($depth > $limits['depth']) {
            $node['cut'] = $count;

            return $node;
        }
        $i = 0;
        foreach ($value as $k => $v) {
            if ($i++ >= $limits['items']) {
                $node['cut'] = $count - $limits['items'];
                break;
            }
            if (is_array($v)) {
                $child = self::plainArray($v, $depth + 1, $limits, $budget);
                if ($child === null) {
                    return null;
                }
            } elseif ($v === null || is_scalar($v)) {
                $child = self::scalar($v);
            } else {
                return null;
            }
            if ($list) {
                $node['items'][] = $child;
            } else {
                $node['entries'][] = [$k, $child];
            }
        }

        return $node;
    }

    /** @param array{depth:int, items:int, nodes:int, string:int} $limits */
    private static function makeCloner(array $limits): VarCloner
    {
        $cloner = new VarCloner();
        $cloner->setMaxItems($limits['nodes']);
        $cloner->setMaxString($limits['string']);
        $cloner->setMinDepth(0);
        $cloner->addCasters([
            'UnitEnum' => [self::class, 'castEnum'],
            'DateTimeInterface' => [self::class, 'castDate'],
            'Closure' => [self::class, 'castClosure'],
        ]);

        return $cloner;
    }

    /** @return array{depth:int, items:int, nodes:int, string:int} */
    private static function limits(): array
    {
        $l = (array) Config::get('limits') + Config::DEFAULTS['limits'];
        $limits = [
            'depth' => max(1, (int) $l['depth']),
            'items' => max(1, (int) $l['items']),
            'nodes' => (int) $l['nodes'],
            'string' => (int) $l['string'],
        ];
        foreach (self::$server as $name => $max) {
            if ($limits[$name] < 0 || $limits[$name] > $max) {
                $limits[$name] = $max;
            }
        }

        return $limits;
    }
}
