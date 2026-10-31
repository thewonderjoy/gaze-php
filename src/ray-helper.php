<?php

declare(strict_types=1);

use TheWonderJoy\Gaze\Config;
use TheWonderJoy\Gaze\Gaze;
use TheWonderJoy\Gaze\Noop;

// Required by Gaze::registerRayHelper() only (not a Composer "files" entry). Off, like gaze(): the
// Noop, and rd() does nothing.

if (!function_exists('ray')) {
    function ray(mixed ...$values): Gaze|Noop
    {
        if (Config::off()) {
            return Noop::instance();
        }

        return $values ? Gaze::make()->send(...$values) : Gaze::make();
    }
}

if (!function_exists('rd')) {
    function rd(mixed ...$values): void
    {
        if (!Config::off()) {
            Gaze::make()->send(...$values)->die();
        }
    }
}
