<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Transport;

use TheWonderJoy\Gaze\Config;
use TheWonderJoy\Gaze\Support\StateFile;

/**
 * Picks the host when none is configured (GAZE_HOST, RAY_HOST or Gaze::configure()).
 *
 * Candidates in order: 127.0.0.1 (localhost), host.docker.internal (when /.dockerenv exists or
 * LARAVEL_SAIL is set), then, only under WSL or in a container, the default gateway from
 * /proc/net/route (WSL2 NAT and containers reach the host through it; on other Linux machines
 * it is a router). With more than one candidate they are
 * probed in parallel (GET /v1/hello, 150 ms connect timeout) and the winner is cached in
 * a temp file for 60 s, so FPM workers do not probe on every request.
 *
 * @internal
 */
final class HostResolver
{
    /**
     * 127.0.0.1 rather than localhost: on Windows a connect to [::1] with nothing listening is not
     * refused but times out, so "localhost" costs a happy-eyeballs delay whenever the app is IPv4-only
     * (measured: 150 ms connect timeout exceeded without tuning). WSL mirrored mode also supports
     * 127.0.0.1 but not ::1.
     */
    public const DEFAULT_HOST = '127.0.0.1';

    public static function resolve(int $port): string
    {
        $host = Config::get('host');
        if (is_string($host) && $host !== '') {
            return $host;
        }
        if (!Config::get('discover')) {
            return self::DEFAULT_HOST;
        }
        $candidates = self::candidates();
        if (count($candidates) === 1) {
            return $candidates[0];
        }

        $cacheKey = 'host-' . $port;
        $cached = StateFile::read($cacheKey);
        if ($cached !== null && ($cached['until'] ?? 0) > time() && in_array($cached['host'] ?? null, $candidates, true)) {
            return $cached['host'];
        }

        $winner = self::probe($candidates, $port);
        StateFile::write($cacheKey, [
            'host' => $winner ?? $candidates[0],
            'found' => $winner !== null,
            'until' => time() + (int) ($winner !== null ? Config::get('host_ttl') : Config::get('down_ttl')),
            'candidates' => $candidates,
        ]);

        return $winner ?? $candidates[0];
    }

    /**
     * `$root` and `$windows` stand in for the file system root and the OS in tests.
     *
     * @return list<string>
     */
    public static function candidates(string $root = '', ?bool $windows = null): array
    {
        $candidates = [self::DEFAULT_HOST];
        if ($windows ?? DIRECTORY_SEPARATOR === '\\') {
            return $candidates; // native Windows: the app is on this machine
        }
        $docker = @file_exists($root . '/.dockerenv') || Config::env('LARAVEL_SAIL') !== null;
        if ($docker) {
            $candidates[] = 'host.docker.internal';
        }
        // The gateway is the Windows host under WSL2 and the host machine in a container. Anywhere
        // else it is a router, and whoever answers on it would get every dump and the token.
        $container = $docker || @file_exists($root . '/run/.containerenv');
        $wsl = stripos((string) @file_get_contents($root . '/proc/version'), 'microsoft') !== false;
        $routeFile = $root . '/proc/net/route';
        if (($container || $wsl) && @is_readable($routeFile)) {
            $gateway = self::gateway((string) @file_get_contents($routeFile));
            if ($gateway !== null) {
                $candidates[] = $gateway;
            }
        }

        return array_values(array_unique($candidates));
    }

    /** Default IPv4 gateway from the text of /proc/net/route (little-endian hex). */
    public static function gateway(string $routeTable): ?string
    {
        foreach (preg_split('/\R/', $routeTable) ?: [] as $line) {
            $cols = preg_split('/\s+/', trim($line)) ?: [];
            if (($cols[1] ?? '') === '00000000' && isset($cols[2]) && preg_match('/^[0-9A-Fa-f]{8}$/', $cols[2]) && $cols[2] !== '00000000') {
                $ip = long2ip((int) hexdec(implode('', array_reverse(str_split($cols[2], 2)))));

                return $ip === false ? null : $ip;
            }
        }

        return null;
    }

    /**
     * Probes candidates in parallel; returns the first one (in candidate order) that answers
     * GET /v1/hello with 200, or null.
     *
     * @param list<string> $candidates
     */
    public static function probe(array $candidates, int $port, ?int $connectTimeoutMs = null): ?string
    {
        $urls = [];
        foreach ($candidates as $host) {
            $urls[$host] = self::baseUrl($host, $port) . '/v1/hello';
        }
        $connect = $connectTimeoutMs ?? (int) Config::get('probe_timeout_ms');
        $results = CurlTransport::probe($urls, ['Accept: application/json'], $connect, $connect + 350);
        foreach ($candidates as $host) {
            $r = $results[$host] ?? null;
            if ($r !== null && $r['errno'] === 0 && $r['status'] === 200) {
                return $host;
            }
        }

        return null;
    }

    public static function baseUrl(string $host, int $port): string
    {
        if (str_contains($host, ':') && !str_starts_with($host, '[')) {
            $host = '[' . $host . ']'; // IPv6 literal
        }

        return 'http://' . $host . ':' . $port;
    }

    public static function isLoopback(string $host): bool
    {
        $h = strtolower(trim($host, '[]'));

        return $h === 'localhost' || $h === '::1' || str_starts_with($h, '127.') || str_ends_with($h, '.localhost');
    }
}
