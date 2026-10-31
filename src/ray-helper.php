<?php

declare(strict_types=1);

use TheWonderJoy\Gaze\Gaze;

// Required by Gaze::registerRayHelper() only (not a Composer "files" entry).

if (!function_exists('ray')) {
    function ray(mixed ...$values): Gaze
    {
        return $values ? Gaze::make()->send(...$values) : Gaze::make();
    }
}

if (!function_exists('rd')) {
    function rd(mixed ...$values): never
    {
        Gaze::make()->send(...$values)->die();
    }
}
