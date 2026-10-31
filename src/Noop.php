<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze;

/**
 * What gaze() returns while Gaze is off (configure(['enabled' => false]), GAZE_ENABLED=false, or
 * APP_ENV=production without GAZE_ENABLED=true). It depends on nothing, so the rest of the package is
 * never loaded. Every method returns the instance, so any chain still works, and does nothing else;
 * enable() too: only configure() or the environment switch Gaze on.
 *
 * Methods that wrap the caller's own code still run it: pass() returns its value, group($name, $fn)
 * returns what $fn returns, measure($fn) calls $fn, and so do the methods a framework package adds
 * with runs() (gaze-laravel: countQueries($fn), showQueries($fn), ...).
 *
 * @mixin Gaze
 */
final class Noop
{
    private static ?self $instance = null;

    /** @var array<string, int> method => position of the callback it runs, returning its result */
    private static array $runs = [];

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Declares methods that run a callback argument and return its result, so they keep doing that while
     * Gaze is off.
     *
     * @param array<string, int> $methods method => position of the callback argument
     */
    public static function runs(array $methods): void
    {
        self::$runs = $methods + self::$runs;
    }

    /** @param array<int|string, mixed> $arguments */
    public function __call(string $method, array $arguments): mixed
    {
        $arguments = array_values($arguments);
        if ($method === 'measure' && ($arguments[0] ?? null) instanceof \Closure) {
            $arguments[0]();
        }
        $callback = isset(self::$runs[$method]) ? $arguments[self::$runs[$method]] ?? null : null;

        return match (true) {
            $method === 'pass' => $arguments[0] ?? null,
            $method === 'enabled' => false,
            $method === 'disabled' => true,
            $method === 'counterValue' => 0,
            $method === 'id' => '',
            $method === 'group' => ($arguments[1] ?? null) instanceof \Closure ? $arguments[1]($this) : $this,
            is_callable($callback) => $callback(),
            default => $this,
        };
    }
}
