<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Value;

use Symfony\Component\VarDumper\Cloner\Cursor;
use Symfony\Component\VarDumper\Cloner\DumperInterface;

/**
 * Walks VarCloner Data (casters stay in charge of what is exposed) and builds an
 * RFC-0001 §5 Value tree.
 *
 * The per-collection item cap is enforced here, not with Data::withMaxItemsPerDepth():
 * that limit stops the whole remaining dump once any one collection reaches it.
 *
 * @internal
 */
final class ValueDumper implements DumperInterface
{
    /** @var array<string, mixed>|null */
    public ?array $root = null;

    /** @var list<array<string, mixed>> references to the open container nodes */
    private array $stack = [];

    /** @var list<bool> false for containers that are dropped or rendered as leaves */
    private array $live = [];

    /** @var list<int> */
    private array $counts = [];

    /** @var list<int> */
    private array $dropped = [];

    /** @var array<int, true> soft-ref indexes already emitted (repeated objects become refs) */
    private array $seen = [];

    /** @var array<string, mixed> sink for dropped nodes */
    private array $void = [];

    public function __construct(private readonly int $maxItems)
    {
    }

    public function dumpScalar(Cursor $cursor, string $type, string|int|float|bool|null $value): void
    {
        if ($type === 'label') {
            return; // Data context label, not part of the value
        }
        $node = match ($type) {
            'NULL' => ['k' => 'null'],
            'boolean' => ['k' => 'bool', 'v' => (bool) $value],
            'integer' => ValueFactory::int((int) $value),
            'double' => ValueFactory::float((float) $value),
            default => ['k' => 'const', 'v' => match (true) {
                $value === null => 'null',
                is_bool($value) => $value ? 'true' : 'false',
                default => (string) $value,
            }],
        };
        $this->attach($cursor, $node);
    }

    public function dumpString(Cursor $cursor, string $str, bool $bin, int $cut): void
    {
        $node = $bin ? ['k' => 'str', 'v' => base64_encode($str), 'enc' => 'b64'] : ['k' => 'str', 'v' => $str];
        if ($cut > 0) {
            $node['cut'] = $cut;
            $node['len'] = ($bin ? strlen($str) : mb_strlen($str, 'UTF-8')) + $cut;
        }
        $this->attach($cursor, $node);
    }

    public function enterHash(Cursor $cursor, int $type, string|int|null $class, bool $hasChild): void
    {
        $leaf = false;
        if ($type === Cursor::HASH_OBJECT) {
            $attr = $cursor->attr;
            if (isset($attr['gaze.enum'])) {
                $node = ['k' => 'const', 'v' => $attr['gaze.enum'], 'cls' => $attr['gaze.cls']];
                if (array_key_exists('gaze.value', $attr)) {
                    $node['val'] = ValueFactory::scalar($attr['gaze.value']);
                }
                $leaf = true;
            } elseif (isset($attr['gaze.date'])) {
                $node = ['k' => 'date', 'iso' => $attr['gaze.date'], 'tz' => $attr['gaze.tz'], 'cls' => $attr['gaze.cls']];
                $leaf = true;
            } elseif (isset($attr['gaze.fn'])) {
                $node = ['k' => 'fn', 'name' => (string) $class];
                if (isset($attr['file'])) {
                    $node['file'] = (string) $attr['file'];
                    $node['line'] = (int) ($attr['line'] ?? 0);
                }
                $leaf = true;
            } elseif ($cursor->softRefTo > 0) {
                if (isset($this->seen[$cursor->softRefTo])) {
                    $node = ['k' => 'ref', 'to' => $cursor->softRefTo, 'cls' => (string) $class];
                    $leaf = true;
                } else {
                    $this->seen[$cursor->softRefTo] = true;
                    $node = ['k' => 'obj', 'cls' => (string) $class, 'id' => $cursor->softRefTo, 'props' => []];
                }
            } else {
                $node = ['k' => 'obj', 'cls' => (string) $class, 'props' => []];
            }
        } elseif ($type === Cursor::HASH_RESOURCE) {
            $node = ['k' => 'res', 'rt' => (string) $class, 'props' => []];
        } elseif ($type === Cursor::HASH_INDEXED) {
            $node = ['k' => 'list', 'count' => (int) $class, 'items' => []];
        } else {
            $node = ['k' => 'map', 'count' => (int) $class, 'entries' => []];
        }

        $slot = &$this->attach($cursor, $node, $live);
        if ($leaf || !$live) {
            $cursor->skipChildren = true;
        }
        $this->stack[] = &$slot;
        $this->live[] = $live && !$leaf;
        $this->counts[] = 0;
        $this->dropped[] = 0;
    }

    public function leaveHash(Cursor $cursor, int $type, string|int|null $class, bool $hasChild, int $cut): void
    {
        $top = count($this->stack) - 1;
        if ($top < 0) {
            return;
        }
        if ($this->live[$top]) {
            $total = ($cut > 0 ? $cut : 0) + $this->dropped[$top];
            if ($total > 0) {
                $this->stack[$top]['cut'] = $total;
            }
        }
        array_pop($this->stack);
        array_pop($this->live);
        array_pop($this->counts);
        array_pop($this->dropped);
    }

    /**
     * Inserts $node under the current container and returns a reference to it.
     *
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function &attach(Cursor $cursor, array $node, ?bool &$live = null): array
    {
        $live = true;
        $top = count($this->stack) - 1;
        if ($top < 0) {
            $this->root = $node;

            return $this->root;
        }
        if (!$this->live[$top] || $this->counts[$top] >= $this->maxItems) {
            if ($this->live[$top]) {
                ++$this->dropped[$top];
            }
            $live = false;
            $this->void = $node;

            return $this->void;
        }
        ++$this->counts[$top];
        $parent = &$this->stack[$top];

        switch ($cursor->hashType) {
            case Cursor::HASH_INDEXED:
                $parent['items'][] = $node;

                return $parent['items'][array_key_last($parent['items'])];

            case Cursor::HASH_ASSOC:
                $parent['entries'][] = [$cursor->hashKey, $node];

                return $parent['entries'][array_key_last($parent['entries'])][1];

            default: // object and resource properties: "\0*\0prot", "\0Class\0priv", "\0~\0meta", "\0+\0dyn"
                $name = (string) $cursor->hashKey;
                $prop = ['n' => $name, 'vis' => 'pub'];
                if (isset($name[0]) && $name[0] === "\0") {
                    $parts = explode("\0", substr($name, 1), 2);
                    $tag = $parts[0];
                    $prop['n'] = $parts[1] ?? '';
                    if ($tag === '*') {
                        $prop['vis'] = 'prot';
                    } elseif ($tag === '~') {
                        $prop['vis'] = 'meta';
                    } elseif ($tag === '+') {
                        $prop['vis'] = 'dyn';
                    } else {
                        $prop['vis'] = 'priv';
                        $prop['decl'] = $tag;
                    }
                }
                $prop['val'] = $node;
                $parent['props'][] = $prop;

                return $parent['props'][array_key_last($parent['props'])]['val'];
        }
    }
}
