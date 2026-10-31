<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Support;

/**
 * Gaze::macro('dumpUser', function (User $u) { return $this->label('user')->send($u->toArray()); });
 * gaze()->dumpUser($user);
 */
trait Macroable
{
    /** @var array<string, callable|object> */
    protected static array $macros = [];

    public static function macro(string $name, callable|object $macro): void
    {
        static::$macros[$name] = $macro;
    }

    /** Registers every public/protected method of $mixin (methods return the macro closure). */
    public static function mixin(object $mixin, bool $replace = true): void
    {
        $methods = (new \ReflectionClass($mixin))->getMethods(\ReflectionMethod::IS_PUBLIC | \ReflectionMethod::IS_PROTECTED);
        foreach ($methods as $method) {
            if ($replace || !static::hasMacro($method->name)) {
                static::macro($method->name, $method->invoke($mixin));
            }
        }
    }

    public static function hasMacro(string $name): bool
    {
        return isset(static::$macros[$name]);
    }

    public static function flushMacros(): void
    {
        static::$macros = [];
    }

    /** @param array<int, mixed> $parameters */
    public static function __callStatic(string $method, array $parameters): mixed
    {
        if (!static::hasMacro($method)) {
            throw new \BadMethodCallException(sprintf('Method %s::%s does not exist.', static::class, $method));
        }
        $macro = static::$macros[$method];
        if ($macro instanceof \Closure) {
            $macro = \Closure::bind($macro, null, static::class);
        }

        return $macro(...$parameters);
    }

    /** @param array<int, mixed> $parameters */
    public function __call(string $method, array $parameters): mixed
    {
        if (!static::hasMacro($method)) {
            throw new \BadMethodCallException(sprintf('Method %s::%s does not exist.', static::class, $method));
        }
        $macro = static::$macros[$method];
        if ($macro instanceof \Closure) {
            $macro = $macro->bindTo($this, static::class);
        }

        return $macro(...$parameters);
    }
}
