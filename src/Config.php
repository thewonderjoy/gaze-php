<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze;

/**
 * Process-wide configuration.
 *
 * Precedence per key: Gaze::configure() > environment (GAZE_*, then RAY_HOST/RAY_PORT) > defaults.
 * A ray.php file is never read (it is include()d from ancestor directories by spatie/ray).
 *
 * @internal Use Gaze::configure() and Gaze::config().
 */
final class Config
{
    public const DEFAULT_PORT = 23517;

    public const DEFAULTS = [
        'enabled' => true,           // GAZE_ENABLED; unset: false when APP_ENV is production/prod
        'host' => null,             // null: discovery (localhost, host.docker.internal, WSL/container gateway)
        'port' => self::DEFAULT_PORT,
        'token' => null,             // Bearer token, sent to non-loopback hosts only
        'project' => null,           // string name or ['name' => ..., 'root' => ...]
        'limits' => [
            'depth' => 8,            // nesting levels per value
            'items' => 200,          // children per array/object
            'nodes' => 5000,         // nodes per value (VarCloner maxItems)
            'string' => 16384,       // characters per string (bytes for binary strings)
        ],
        'connect_timeout_ms' => 100, // per send; Windows does not refuse fast on a closed loopback port
        'timeout_ms' => 2000,        // total per batch request
        'probe_timeout_ms' => 150,   // connect timeout for parallel host discovery probes
        'drain_ms' => 250,           // shutdown drain cap
        'linger_ms' => 2,            // end of a call: wait up to this for queued entries to get on the wire (0: off)
        'flush_timeout_ms' => 2000,  // synchronous flush cap (flush(), pause(), gaze_dd())
        'down_ttl' => 30,            // seconds a failed host is skipped by every process
        'hello_ttl' => 60,           // seconds a successful handshake is trusted by every process
        'host_ttl' => 60,            // seconds a discovered host is cached
        'state_dir' => null,         // null: sys_get_temp_dir()
        'origin_frames' => 3,        // extra "called from" frames attached to each entry
        'discover' => true,          // probe docker/WSL candidates when no host is configured
        'client_name' => 'thewonderjoy/gaze-php',
        'client_version' => null,    // null: Gaze::VERSION
        'framework' => null,
        'framework_version' => null,
        'logger' => null,            // callable(string $message): void; null: error_log(); false: silent
    ];

    /** Hot-path flag read by the fluent API: on (configure()/environment) and not paused by disable(). */
    public static bool $enabled = true;

    /** disable() was called, and enable() has not been since. */
    private static bool $paused = false;

    /** @var array<string, mixed>|null */
    private static ?array $options = null;

    /** @var array<string, mixed> */
    private static array $overrides = [];

    /** @return array<string, mixed> */
    public static function all(): array
    {
        return self::$options ??= self::resolve();
    }

    public static function get(string $key): mixed
    {
        return (self::$options ??= self::resolve())[$key] ?? null;
    }

    /**
     * Off through configure() or the environment: gaze() returns the Noop, and enable() cannot switch
     * Gaze on. Reads nothing but the environment.
     */
    public static function off(): bool
    {
        return !(self::$options ??= self::resolve())['enabled'];
    }

    /** enable()/disable(): pauses sending within what configure() and the environment allow. */
    public static function pause(bool $paused): void
    {
        self::$paused = $paused;
        self::$enabled = !self::off() && !$paused;
    }

    /** @param array<string, mixed> $options */
    public static function set(array $options): void
    {
        if (array_key_exists('enabled', $options)) {
            self::$paused = false;
        }
        if (isset($options['limits']) && is_array($options['limits'])) {
            $options['limits'] += self::$overrides['limits'] ?? [];
        }
        self::$overrides = $options + self::$overrides;
        self::$options = null;
        self::all();
    }

    public static function reset(): void
    {
        self::$overrides = [];
        self::$paused = false;
        self::$options = null;
        self::all();
    }

    public static function hostIsConfigured(): bool
    {
        return self::get('host') !== null;
    }

    public static function env(string $name): ?string
    {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);
        if (!is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /** APP_ENV is "production" or "prod" (any case). */
    public static function isProduction(): bool
    {
        return in_array(strtolower((string) self::env('APP_ENV')), ['production', 'prod'], true);
    }

    public static function bool(mixed $value, bool $default): bool
    {
        if ($value === null) {
            return $default;
        }
        if (is_bool($value)) {
            return $value;
        }

        return !in_array(strtolower(trim((string) $value, " \t\n\r\0\x0B()")), ['0', 'false', 'off', 'no', 'disabled', ''], true);
    }

    /** @return array<string, mixed> */
    private static function resolve(): array
    {
        $o = self::$overrides + self::DEFAULTS;
        $o['limits'] = (self::$overrides['limits'] ?? []) + self::DEFAULTS['limits'];

        // Off by default when APP_ENV says production (Laravel "production", Symfony "prod"), so a
        // regular (non --dev) install keeps leftover gaze() calls silent on a live server.
        $o['enabled'] = self::bool(
            array_key_exists('enabled', self::$overrides) ? self::$overrides['enabled'] : self::env('GAZE_ENABLED'),
            !self::isProduction(),
        );
        $o['host'] = self::$overrides['host'] ?? self::env('GAZE_HOST') ?? self::env('RAY_HOST');
        $port = self::$overrides['port'] ?? self::env('GAZE_PORT') ?? self::env('RAY_PORT');
        $o['port'] = is_numeric($port) && (int) $port > 0 && (int) $port < 65536 ? (int) $port : self::DEFAULT_PORT;
        $o['token'] = self::$overrides['token'] ?? self::env('GAZE_TOKEN');
        $o['project'] ??= self::env('GAZE_PROJECT');
        $o['state_dir'] ??= sys_get_temp_dir();

        self::$enabled = $o['enabled'] && !self::$paused;

        return $o;
    }
}
