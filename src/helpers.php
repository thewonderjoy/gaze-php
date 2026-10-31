<?php

declare(strict_types=1);

use TheWonderJoy\Gaze\Config;
use TheWonderJoy\Gaze\Gaze;
use TheWonderJoy\Gaze\Noop;

// Loaded through Composer "files". Defines only our own helpers: never ray() (Composer loads
// equal-weight packages alphabetically, so a ray() here could shadow spatie/ray's).
// For ray()/rd() call Gaze::registerRayHelper() explicitly.
// While Gaze is off they load nothing but Config (to read the environment) and Noop.

if (!function_exists('gaze')) {
    /**
     * gaze($a, $b) sends values; gaze() returns an instance for the fluent API. Off, it returns the Noop.
     */
    function gaze(mixed ...$values): Gaze|Noop
    {
        if (Config::off()) {
            return Noop::instance();
        }

        return $values ? Gaze::make()->send(...$values) : Gaze::make();
    }
}

if (!function_exists('gaze_dd')) {
    /**
     * Sends the values, waits until the app has them, then exits (status 1, like dd()). Off, it does
     * nothing and returns.
     */
    function gaze_dd(mixed ...$values): void
    {
        if (!Config::off()) {
            Gaze::make()->send(...$values)->die(1);
        }
    }
}
