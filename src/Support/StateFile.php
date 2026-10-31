<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Support;

use TheWonderJoy\Gaze\Config;

/**
 * Small JSON files in the temp directory, shared by every PHP process on the machine
 * (FPM workers, queue workers, CLI). Statics are reset per request under FPM, so the
 * "app is down" and "handshake ok" results must live outside the process.
 *
 * @internal
 */
final class StateFile
{
    public static function path(string $name): string
    {
        return rtrim((string) Config::get('state_dir'), '/\\') . DIRECTORY_SEPARATOR . 'gaze-' . $name . '.json';
    }

    /** @return array<string, mixed>|null */
    public static function read(string $name): ?array
    {
        $raw = @file_get_contents(self::path($name));
        if ($raw === false || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);

        return is_array($data) ? $data : null;
    }

    /** @param array<string, mixed> $data */
    public static function write(string $name, array $data): void
    {
        $path = self::path($name);
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        $tmp = $path . '.' . getmypid() . '.' . bin2hex(random_bytes(3)) . '.tmp';
        if (@file_put_contents($tmp, $json) !== false && @rename($tmp, $path)) {
            return;
        }
        @unlink($tmp);
        @file_put_contents($path, $json, LOCK_EX);
    }

    public static function delete(string $name): void
    {
        @unlink(self::path($name));
    }

    /** Key for per-endpoint state: hostname and port, safe as a file name. */
    public static function endpointKey(string $host, int $port): string
    {
        return 'state-' . substr(md5(strtolower($host) . ':' . $port), 0, 16);
    }
}
