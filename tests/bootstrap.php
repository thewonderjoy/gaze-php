<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

// Tests must not inherit the developer's real endpoint settings.
foreach (['GAZE_ENABLED', 'GAZE_HOST', 'GAZE_PORT', 'GAZE_TOKEN', 'GAZE_PROJECT', 'RAY_HOST', 'RAY_PORT', 'APP_ENV'] as $name) {
    putenv($name);
    unset($_ENV[$name], $_SERVER[$name]);
}
