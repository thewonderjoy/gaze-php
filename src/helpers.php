<?php

declare(strict_types=1);

use TheWonderJoy\Gaze\Gaze;

// Loaded through Composer "files". Defines only our own helpers: never ray() (Composer loads
// equal-weight packages alphabetically, so a ray() here could shadow spatie/ray's).
// For ray()/rd() call Gaze::registerRayHelper() explicitly.

if (!function_exists('gaze')) {
    /**
     * gaze($a, $b) sends values; gaze() returns an instance for the fluent API.
     */
    function gaze(mixed ...$values): Gaze
    {
        return $values ? Gaze::make()->send(...$values) : Gaze::make();
    }
}

if (!function_exists('gaze_dd')) {
    /**
     * Sends the values, waits until the app has them, then exits (status 1, like dd()).
     */
    function gaze_dd(mixed ...$values): never
    {
        Gaze::make()->send(...$values)->die(1);
    }
}
