<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Exceptions;

/**
 * Thrown by pause() when "Stop execution" is chosen in the app.
 */
final class StopExecutionRequested extends \RuntimeException
{
    public static function make(): self
    {
        return new self('This exception is thrown because you requested to stop the execution in Gaze.');
    }
}
