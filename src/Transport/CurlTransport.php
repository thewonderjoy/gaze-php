<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Transport;

/**
 * Non-blocking HTTP on one curl_multi handle, at most one request in flight.
 *
 * The multi handle owns the connection cache, so the handshake, every batch POST and the
 * lock long-polls reuse one keep-alive connection. Nothing here throws: failures come
 * back as a result array with errno/status.
 *
 * @internal
 */
final class CurlTransport
{
    private \CurlMultiHandle $multi;

    private ?\CurlHandle $handle = null;

    private ?\CurlHandle $post = null;

    private ?\CurlHandle $active = null;

    /** @param list<string> $headers */
    public function __construct(
        private readonly string $baseUrl,
        private readonly array $headers,
        private readonly int $connectTimeoutMs,
    ) {
        $this->multi = curl_multi_init();
    }

    public function __destruct()
    {
        $this->abort();
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    public function busy(): bool
    {
        return $this->active !== null;
    }

    /**
     * Starts a batch POST; progress is made by poll()/wait(). The handle keeps its options
     * between batches; only the body changes.
     *
     * On a reused keep-alive connection the first curl_multi_exec() writes the whole request.
     * On a new connection it only starts the connect, so the request would sit in the process
     * until the next call; $sendWaitMs bounds a short wait until the body is on the wire.
     */
    public function startPost(string $path, string $body, int $timeoutMs, float $sendWaitMs = 0.0): void
    {
        $this->abort();
        if ($this->post === null) {
            $this->post = curl_init();
            curl_setopt_array($this->post, [
                CURLOPT_URL => $this->baseUrl . $path,
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CONNECTTIMEOUT_MS => $this->connectTimeoutMs,
                CURLOPT_TIMEOUT_MS => $timeoutMs,
                CURLOPT_TCP_NODELAY => true,
                CURLOPT_NOSIGNAL => true,
                CURLOPT_HTTPHEADER => $this->headers,
                CURLOPT_PROXY => '',
                CURLOPT_FOLLOWLOCATION => false,
            ] + self::happyEyeballs());
        }
        curl_setopt($this->post, CURLOPT_POSTFIELDS, $body);
        curl_multi_add_handle($this->multi, $this->post);
        $this->active = $this->post;
        if (!$this->exec() || $sendWaitMs <= 0) {
            return;
        }
        $size = strlen($body);
        $deadline = microtime(true) + $sendWaitMs / 1000;
        while (self::uploaded($this->post) < $size) {
            $left = $deadline - microtime(true);
            if ($left <= 0) {
                return;
            }
            curl_multi_select($this->multi, self::slice($left));
            if (!$this->exec()) {
                return;
            }
        }
    }

    /**
     * Windows rounds select()/usleep() timeouts of 1 ms or more up to its 15.6 ms timer tick
     * (measured: select(1 ms) = 15.6 ms, select(0.5 ms) = 12 us). Short waits therefore poll in
     * sub-millisecond slices; long waits still sleep until socket activity.
     */
    private static function slice(float $left): float
    {
        return $left < 0.02 ? min($left, 0.0005) : min($left, 0.05);
    }

    private static function uploaded(\CurlHandle $h): int
    {
        return defined('CURLINFO_SIZE_UPLOAD_T') ? (int) curl_getinfo($h, CURLINFO_SIZE_UPLOAD_T) : (int) curl_getinfo($h, CURLINFO_SIZE_UPLOAD);
    }

    /** Starts any request and returns at once. */
    public function start(string $method, string $path, ?string $body, int $timeoutMs, ?int $connectTimeoutMs = null): void
    {
        $this->abort();
        $h = $this->handle ??= curl_init();
        curl_reset($h);
        $opts = [
            CURLOPT_URL => $this->baseUrl . $path,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CONNECTTIMEOUT_MS => $connectTimeoutMs ?? $this->connectTimeoutMs,
            CURLOPT_TIMEOUT_MS => $timeoutMs,
            CURLOPT_TCP_NODELAY => true,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_HTTPHEADER => $this->headers,
            CURLOPT_PROXY => '',
            CURLOPT_FOLLOWLOCATION => false,
        ] + self::happyEyeballs();
        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = (string) $body;
        } else {
            $opts[CURLOPT_HTTPGET] = true;
        }
        curl_setopt_array($h, $opts);
        curl_multi_add_handle($this->multi, $h);
        $this->active = $h;
        $this->exec();
    }

    /**
     * Makes progress without blocking.
     *
     * @return array{status:int, errno:int, error:string, body:string, ms:float}|null null while still running
     */
    public function poll(): ?array
    {
        if ($this->active === null) {
            return null;
        }
        if ($this->exec()) {
            return null;
        }

        return $this->collect();
    }

    /**
     * Blocks until the request in flight finishes or $deadline (microtime) passes.
     *
     * @return array{status:int, errno:int, error:string, body:string, ms:float}|null null on deadline
     */
    public function wait(float $deadline): ?array
    {
        while ($this->active !== null) {
            if (!$this->exec()) {
                return $this->collect();
            }
            $left = $deadline - microtime(true);
            if ($left <= 0) {
                return null;
            }
            curl_multi_select($this->multi, self::slice($left));
        }

        return null;
    }

    /**
     * Blocking request (handshake, lock polls, synchronous flush).
     *
     * @return array{status:int, errno:int, error:string, body:string, ms:float}
     */
    public function request(string $method, string $path, ?string $body, int $timeoutMs, ?int $connectTimeoutMs = null): array
    {
        $this->start($method, $path, $body, $timeoutMs, $connectTimeoutMs);
        $result = $this->wait(microtime(true) + $timeoutMs / 1000 + 0.05);
        if ($result === null) {
            $this->abort();

            return ['status' => 0, 'errno' => CURLE_OPERATION_TIMEDOUT, 'error' => 'timeout', 'body' => '', 'ms' => (float) $timeoutMs];
        }

        return $result;
    }

    public function abort(): void
    {
        if ($this->active !== null) {
            curl_multi_remove_handle($this->multi, $this->active);
            $this->active = null;
        }
    }

    /**
     * A name such as "localhost" resolves to ::1 and 127.0.0.1. On Windows a connect to a port
     * nobody listens on is not refused but times out, and libcurl waits 200 ms before trying the
     * other family; 25 ms keeps an IPv4-only listener reachable within the 100 ms connect timeout.
     *
     * @return array<int, int>
     */
    private static function happyEyeballs(): array
    {
        return defined('CURLOPT_HAPPY_EYEBALLS_TIMEOUT_MS') ? [CURLOPT_HAPPY_EYEBALLS_TIMEOUT_MS => 25] : [];
    }

    /** @return bool true while the transfer is still running */
    private function exec(): bool
    {
        do {
            $status = curl_multi_exec($this->multi, $running);
        } while ($status === CURLM_CALL_MULTI_PERFORM);

        return $status === CURLM_OK && $running > 0;
    }

    /** @return array{status:int, errno:int, error:string, body:string, ms:float} */
    private function collect(): array
    {
        $h = $this->active;
        $errno = 0;
        while (($msg = curl_multi_info_read($this->multi)) !== false) {
            if (($msg['handle'] ?? null) === $h) {
                $errno = (int) $msg['result'];
            }
        }
        $result = [
            'status' => (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE),
            'errno' => $errno,
            'error' => $errno !== 0 ? (curl_strerror($errno) ?? 'curl error ' . $errno) : '',
            'body' => (string) curl_multi_getcontent($h),
            'ms' => round((float) curl_getinfo($h, CURLINFO_TOTAL_TIME) * 1000, 3),
        ];
        curl_multi_remove_handle($this->multi, $h);
        $this->active = null;

        return $result;
    }

    /**
     * Probes several base URLs in parallel (GET $path) and returns their results by key.
     *
     * @param array<string, string> $urls key => full URL
     * @param list<string> $headers
     * @return array<string, array{status:int, errno:int, body:string}>
     */
    public static function probe(array $urls, array $headers, int $connectTimeoutMs, int $timeoutMs): array
    {
        $multi = curl_multi_init();
        $handles = [];
        foreach ($urls as $key => $url) {
            $h = curl_init($url);
            curl_setopt_array($h, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT_MS => $connectTimeoutMs,
                CURLOPT_TIMEOUT_MS => $timeoutMs,
                CURLOPT_NOSIGNAL => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_PROXY => '',
            ] + self::happyEyeballs());
            curl_multi_add_handle($multi, $h);
            $handles[$key] = $h;
        }
        $deadline = microtime(true) + $timeoutMs / 1000 + 0.05;
        do {
            do {
                $status = curl_multi_exec($multi, $running);
            } while ($status === CURLM_CALL_MULTI_PERFORM);
            if ($running > 0) {
                curl_multi_select($multi, self::slice(max(0.0, $deadline - microtime(true))));
            }
        } while ($running > 0 && $status === CURLM_OK && microtime(true) < $deadline);
        $errnos = [];
        while (($msg = curl_multi_info_read($multi)) !== false) {
            $key = array_search($msg['handle'], $handles, true);
            if ($key !== false) {
                $errnos[$key] = (int) $msg['result'];
            }
        }
        $out = [];
        foreach ($handles as $key => $h) {
            $out[$key] = [
                'status' => (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE),
                'errno' => $errnos[$key] ?? CURLE_OPERATION_TIMEDOUT,
                'body' => (string) curl_multi_getcontent($h),
            ];
            curl_multi_remove_handle($multi, $h);
        }
        curl_multi_close($multi);

        return $out;
    }
}
