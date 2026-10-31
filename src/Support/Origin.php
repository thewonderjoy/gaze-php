<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Support;

/**
 * Finds where user code called into Gaze: the first stack frame whose call site is
 * outside this package (and outside any path added with addInternalPath()).
 *
 * @internal
 */
final class Origin
{
    /** @var list<string>|null */
    private static ?array $internal = null;

    /** Treat call sites under $path as Gaze internals (framework packages register themselves). */
    public static function addInternalPath(string $path): void
    {
        self::$internal ??= self::defaults();
        $real = realpath($path);
        foreach (array_unique([$path, $real === false ? $path : $real]) as $p) {
            $p = rtrim($p, '/\\');
            if (!in_array($p, self::$internal, true)) {
                self::$internal[] = $p;
            }
        }
    }

    public static function isInternal(string $file): bool
    {
        foreach (self::$internal ??= self::defaults() as $dir) {
            if (str_starts_with($file, $dir)) {
                $next = $file[strlen($dir)] ?? '';
                if ($next === '/' || $next === '\\' || $next === '') {
                    return true;
                }
            }
        }

        return false;
    }

    public static function isVendor(string $file): bool
    {
        return str_contains($file, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR) || str_contains($file, '/vendor/');
    }

    /**
     * Captures the caller and returns [origin, index of the origin frame in $trace, $trace].
     *
     * @return array{0: array<string, mixed>|null, 1: int, 2: list<array<string, mixed>>}
     */
    public static function capture(int $extraFrames = 0): array
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 16 + 2 * $extraFrames);
        $n = count($trace);
        for ($i = 1; $i < $n; ++$i) {
            $file = $trace[$i]['file'] ?? null;
            if ($file === null || self::isInternal($file)) {
                continue;
            }
            $origin = ['file' => $file];
            $line = (int) ($trace[$i]['line'] ?? 0);
            if ($line > 0) {
                $origin['line'] = $line;
            }
            $outer = $trace[$i + 1] ?? null;
            if ($outer !== null) {
                if (isset($outer['function'])) {
                    $origin['function'] = $outer['function'];
                }
                if (isset($outer['class'])) {
                    $origin['class'] = $outer['class'];
                }
            }
            if ($extraFrames > 0) {
                $frames = [];
                for ($j = $i + 1; $j < $n && count($frames) < $extraFrames; ++$j) {
                    $f = $trace[$j];
                    if (!isset($f['file']) || self::isInternal($f['file']) || self::isVendor($f['file'])) {
                        continue;
                    }
                    $frames[] = self::frame($f, $trace[$j + 1] ?? null);
                }
                if ($frames) {
                    $origin['frames'] = $frames;
                }
            }

            return [$origin, $i, $trace];
        }

        return [null, $n, $trace];
    }

    /**
     * Stack frames (outermost last) starting at the caller of Gaze, for trace()/caller().
     *
     * @return list<array<string, mixed>>
     */
    public static function backtrace(int $limit = 100): array
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $limit + 16);
        $n = count($trace);
        $frames = [];
        $started = false;
        for ($i = 1; $i < $n && count($frames) < $limit; ++$i) {
            $file = $trace[$i]['file'] ?? null;
            if (!$started) {
                if ($file === null || self::isInternal($file)) {
                    continue;
                }
                $started = true;
            }
            if ($file === null) {
                continue;
            }
            $frames[] = self::frame($trace[$i], $trace[$i + 1] ?? null);
        }

        return $frames;
    }

    /**
     * Frames of a Throwable, innermost first: the throw site, then each call site.
     *
     * @return list<array<string, mixed>>
     */
    public static function throwableFrames(\Throwable $e, int $limit = 100, int $snippets = 3): array
    {
        $trace = $e->getTrace();
        $frames = [];
        $site = ['file' => $e->getFile(), 'line' => $e->getLine()];
        $frames[] = self::frame($site, $trace[0] ?? null);
        foreach ($trace as $i => $t) {
            if (count($frames) >= $limit) {
                break;
            }
            if (!isset($t['file'])) {
                continue;
            }
            $frames[] = self::frame($t, $trace[$i + 1] ?? null);
        }
        $added = 0;
        foreach ($frames as &$frame) {
            if ($added >= $snippets) {
                break;
            }
            if (!$frame['vendor'] && ($snippet = self::snippet($frame['file'], $frame['line'])) !== null) {
                $frame['snippet'] = $snippet;
                ++$added;
            }
        }
        unset($frame);

        return $frames;
    }

    /**
     * @param array<string, mixed> $callSite frame whose file/line is the call site
     * @param array<string, mixed>|null $outer frame of the function containing the call site
     * @return array<string, mixed>
     */
    public static function frame(array $callSite, ?array $outer): array
    {
        $file = (string) ($callSite['file'] ?? '');
        $frame = ['file' => $file, 'line' => max(0, (int) ($callSite['line'] ?? 0))];
        if ($outer !== null) {
            if (isset($outer['class'])) {
                $frame['class'] = $outer['class'];
            }
            if (isset($outer['function'])) {
                $frame['function'] = $outer['function'];
            }
        }
        $frame['vendor'] = self::isVendor($file);

        return $frame;
    }

    /** @return list<array{line:int, text:string}>|null */
    public static function snippet(string $file, int $line, int $around = 2): ?array
    {
        if ($line < 1 || !@is_file($file) || !@is_readable($file) || @filesize($file) > 4 * 1024 * 1024) {
            return null;
        }
        $lines = @file($file, FILE_IGNORE_NEW_LINES);
        if ($lines === false || !isset($lines[$line - 1])) {
            return null;
        }
        $out = [];
        for ($l = max(1, $line - $around); $l <= min(count($lines), $line + $around); ++$l) {
            $text = rtrim($lines[$l - 1], "\r");
            $out[] = ['line' => $l, 'text' => strlen($text) > 400 ? substr($text, 0, 400) : $text];
        }

        return $out;
    }

    /** @return list<string> */
    private static function defaults(): array
    {
        $src = dirname(__DIR__);
        $real = realpath($src);

        return array_values(array_unique([$src, $real === false ? $src : $real]));
    }
}
