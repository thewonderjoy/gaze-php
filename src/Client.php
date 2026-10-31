<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze;

use Composer\InstalledVersions;
use TheWonderJoy\Gaze\Support\StateFile;
use TheWonderJoy\Gaze\Support\Uuid;
use TheWonderJoy\Gaze\Transport\CurlTransport;
use TheWonderJoy\Gaze\Transport\HostResolver;
use TheWonderJoy\Gaze\Value\ValueFactory;

/**
 * Per-process queue and transport (RFC-0001 §4, note 12 §3.3).
 *
 * - Async-immediate: every call enqueues and pumps; when no request is in flight a
 *   non-blocking curl_multi POST starts at once. While one is in flight, entries coalesce
 *   into the next batch.
 * - Chains: the newest "add" entry is held while its Gaze instance may still receive
 *   chained modifiers; it is released when that instance is destroyed (end of the
 *   statement for gaze(...)->red()), when another instance is used, on flush() and at
 *   shutdown. Modifiers on an entry that is still queued are folded into it.
 * - Reachability: a shared temp file per host:port records "down" (30 s) and a successful
 *   handshake (60 s), so a closed app costs one 100 ms connect timeout per 30 s for the
 *   whole machine, not per request.
 * - Never throws into user code.
 *
 * @internal Use Gaze / gaze(); Gaze::client() exposes status() and flush() for tooling and tests.
 */
final class Client
{
    public const UNKNOWN = 0;
    public const UP = 1;
    public const DOWN = 2;

    public const MAX_QUEUE = 10000;
    public const MAX_BATCH = 5000;

    public const JSON_FLAGS = JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

    private static ?self $instance = null;

    private static bool $shutdownRegistered = false;

    private int $state = self::UNKNOWN;

    private float $retryAt = 0.0;

    private ?string $host = null;

    private int $port = Config::DEFAULT_PORT;

    private ?CurlTransport $transport = null;

    /** @var array<string, mixed>|null */
    private ?array $hello = null;

    /** @var array<int, array<string, mixed>> entries not yet handed to curl, in send order */
    private array $queue = [];

    private int $nextKey = 0;

    /** @var array<string, int> entry id => queue key of its newest queued entry */
    private array $latest = [];

    private ?int $holdKey = null;

    private ?int $holdOwner = null;

    /** @var list<array<string, mixed>>|null */
    private ?array $inflight = null;

    private int $lastStatus = 0;

    private int $throttled = 0;

    /** Consecutive batches requeued after a transfer timeout (see finish()). */
    private int $timeoutRetries = 0;

    private float $throttleUntil = 0.0;

    private string $session;

    private int $seq = 0;

    /** @var array<string, mixed>|null */
    private ?array $clientInfo = null;

    /** @var array<string, mixed>|null */
    private ?array $trace = null;

    /** @var list<array<string, mixed>|null> */
    private array $traceStack = [];

    /** @var array<string, true> */
    private array $logged = [];

    private bool $drained = false;

    /** @var array{batches:int, entries:int, dropped:int, failures:int} */
    private array $stats = ['batches' => 0, 'entries' => 0, 'dropped' => 0, 'failures' => 0];

    private function __construct()
    {
        $this->session = Uuid::v7();
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public static function current(): ?self
    {
        return self::$instance;
    }

    /** Forgets everything, including queued entries (tests, long-running workers between units). */
    public static function reset(): void
    {
        self::$instance?->transport?->abort();
        self::$instance = null;
    }

    /**
     * Applies new configuration. Client info is rebuilt; when $transport is true the connection
     * and the reachability memo are dropped too (the queue is kept).
     */
    public function reconfigure(bool $transport = true): void
    {
        $this->clientInfo = null;
        if (!$transport) {
            return;
        }
        $this->transport?->abort();
        $this->transport = null;
        $this->inflight = null;
        $this->state = self::UNKNOWN;
        $this->host = null;
        $this->hello = null;
        $this->retryAt = 0.0;
    }

    public function session(): string
    {
        return $this->session;
    }

    /**
     * True when entries can be sent. Resolves the host and reachability on first use
     * (a temp-file read; a synchronous handshake only when the shared cache is stale).
     */
    public function accepting(): bool
    {
        if ($this->state === self::UP) {
            return true;
        }
        if ($this->state === self::DOWN && microtime(true) < $this->retryAt) {
            return false;
        }

        return $this->resolveState();
    }

    /** Ends the open chain unless it belongs to $owner. */
    public function touch(int $owner): void
    {
        if ($this->holdOwner !== null && $this->holdOwner !== $owner) {
            $this->holdKey = $this->holdOwner = null;
            $this->pump(true);
        }
    }

    /** Ends the open chain (of $owner only, when given). */
    public function release(?int $owner = null): void
    {
        if ($owner === null || $owner === $this->holdOwner) {
            $this->holdKey = $this->holdOwner = null;
        }
    }

    /** Called from Gaze::__destruct(): the chain of $owner is complete. */
    public function closeChain(int $owner): void
    {
        if ($this->holdOwner !== $owner) {
            return;
        }
        $this->holdKey = $this->holdOwner = null;
        if ($this->drained) {
            $this->flush((int) Config::get('drain_ms'));
        } else {
            $this->pump(true);
        }
    }

    /**
     * @param array<string, mixed> $entry op, id, kind, body, modifiers ... (seq is assigned here)
     * @param bool $hold keep it unsent while $owner may still chain modifiers onto it
     */
    public function enqueue(array $entry, ?int $owner = null, bool $hold = false): void
    {
        if (isset($entry['kind']) && $entry['kind'] !== 'cmd' && $this->trace !== null && !isset($entry['trace'])) {
            $entry['trace'] = $this->trace;
        }
        $entry['seq'] = $this->seq++;
        $key = $this->nextKey++;
        $this->queue[$key] = $entry;
        $this->latest[$entry['id']] = $key;
        if ($hold && $owner !== null) {
            $this->holdKey = $key;
            $this->holdOwner = $owner;
        }
        if (count($this->queue) > self::MAX_QUEUE) {
            $this->dropOldest();
        }
        if (!self::$shutdownRegistered) {
            self::$shutdownRegistered = true;
            register_shutdown_function([self::class, 'onShutdown']);
        }
    }

    /**
     * Folds modifiers into the newest queued entry for $id. Returns false when that entry
     * has already been sent (the caller then enqueues an op:patch).
     *
     * @param array<string, mixed> $mods
     */
    public function fold(string $id, array $mods): bool
    {
        $key = $this->latest[$id] ?? null;
        if ($key === null || !isset($this->queue[$key])) {
            return false;
        }
        $op = $this->queue[$key]['op'];
        if ($op !== 'add' && $op !== 'update' && $op !== 'patch') {
            return false;
        }
        foreach ($mods as $name => $value) {
            $this->queue[$key][$name] = $value;
        }

        return true;
    }

    /**
     * Progress without blocking: finishes the request in flight and starts the next batch.
     *
     * With $linger (end of a public call), entries still queued behind a request in flight get
     * up to linger_ms for that request to finish so they leave before control returns to user
     * code. Without it, gaze('a'); gaze('b'); sleep(10); could hold 'b' until the next call.
     * On loopback the round trip is ~40 µs, so this rarely waits; linger_ms = 0 disables it.
     */
    public function pump(bool $linger = false): void
    {
        try {
            if ($this->transport !== null && $this->transport->busy()) {
                $result = $this->transport->poll();
                if ($result === null) {
                    if (!$linger || !$this->hasReleasable() || ($ms = (float) Config::get('linger_ms')) <= 0) {
                        return; // still in flight: entries coalesce
                    }
                    $result = $this->transport->wait(microtime(true) + $ms / 1000);
                    if ($result === null) {
                        return;
                    }
                }
                $this->finish($result);
            }
            $this->startNext();
        } catch (\Throwable $e) {
            $this->log('pump', 'send failed: ' . $e->getMessage());
        }
    }

    private function hasReleasable(): bool
    {
        $first = array_key_first($this->queue);

        return $first !== null && $first !== $this->holdKey && $this->state === self::UP;
    }

    /**
     * Synchronous flush: ends the open chain and waits until everything queued was answered,
     * or the timeout passed (default flush_timeout_ms). Returns true when nothing is left.
     */
    public function flush(?int $timeoutMs = null): bool
    {
        $this->holdKey = $this->holdOwner = null;
        try {
            $deadline = microtime(true) + ($timeoutMs ?? (int) Config::get('flush_timeout_ms')) / 1000;
            if ($this->queue && $this->state !== self::UP && !$this->accepting()) {
                return false; // queued before a reconfigure, and the (new) endpoint is down
            }
            while (true) {
                if ($this->transport !== null && $this->transport->busy()) {
                    $result = $this->transport->wait($deadline);
                    if ($result === null) {
                        return false;
                    }
                    $this->finish($result);
                }
                if ($this->throttleUntil > microtime(true) && $this->queue) {
                    $sleep = min($this->throttleUntil, $deadline) - microtime(true);
                    if ($sleep <= 0 || $this->throttleUntil > $deadline) {
                        return false;
                    }
                    usleep((int) ($sleep * 1e6));
                }
                if (!$this->startNext()) {
                    break;
                }
            }

            return $this->queue === [];
        } catch (\Throwable $e) {
            $this->log('flush', 'flush failed: ' . $e->getMessage());

            return false;
        }
    }

    /** @internal register_shutdown_function target: drain for at most drain_ms. */
    public static function onShutdown(): void
    {
        $client = self::$instance;
        if ($client === null) {
            return;
        }
        $client->drain();
        if (!$client->drained) {
            $client->drained = true;
            // Shutdown functions registered after ours may still log: drain once more after them.
            register_shutdown_function(static fn () => self::$instance?->drain());
        }
    }

    public function drain(): void
    {
        if ($this->queue || ($this->transport !== null && $this->transport->busy())) {
            $this->flush((int) Config::get('drain_ms'));
        }
    }

    /**
     * Sends a lock entry synchronously and long-polls GET /v1/locks/{id}?wait=25.
     *
     * @param array<string, mixed> $entry
     * @return string released | stopped | gone (404: unknown lock, app restarted) | unavailable
     */
    public function lock(array $entry): string
    {
        $this->enqueue($entry);
        if (!$this->flush() || $this->state !== self::UP || $this->lastStatus < 200 || $this->lastStatus >= 300) {
            return 'unavailable';
        }
        $path = '/v1/locks/' . rawurlencode((string) $entry['id']) . '?wait=25';
        while (true) {
            $started = microtime(true);
            $r = $this->transport()->request('GET', $path, null, 30000);
            if ($r['errno'] !== 0 || $r['status'] === 0) {
                return 'unavailable';
            }
            if ($r['status'] === 404) {
                return 'gone';
            }
            if ($r['status'] !== 200) {
                if ($r['status'] === 429 || $r['status'] >= 500) {
                    usleep(500000);
                    continue;
                }

                return 'unavailable';
            }
            $data = json_decode($r['body'], true);
            $state = is_array($data) ? ($data['state'] ?? null) : null;
            if ($state === null && is_array($data) && array_key_exists('active', $data)) {
                $state = !empty($data['stop_execution']) ? 'stopped' : ($data['active'] ? 'active' : 'released');
            }
            if ($state === 'released' || $state === 'stopped') {
                return $state;
            }
            if ($state !== 'active') {
                return 'unavailable';
            }
            if (microtime(true) - $started < 0.2) {
                usleep(250000); // the server did not hold the poll: do not spin
            }
        }
    }

    /** @return array<string, mixed>|null */
    public function traceContext(): ?array
    {
        return $this->trace;
    }

    /** @param array<string, mixed>|null $trace */
    public function setTrace(?array $trace): void
    {
        $this->trace = $trace;
    }

    /** @param array<string, mixed> $trace */
    public function pushTrace(array $trace): void
    {
        $this->traceStack[] = $this->trace;
        $this->trace = $trace;
    }

    /** @return array<string, mixed>|null the trace that was closed */
    public function popTrace(): ?array
    {
        if ($this->traceStack === []) {
            return null; // no group open: keep the request/job trace
        }
        $closed = $this->trace;
        $this->trace = array_pop($this->traceStack);

        return $closed;
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        return [
            'state' => [self::UNKNOWN => 'unknown', self::UP => 'up', self::DOWN => 'down'][$this->state],
            'host' => $this->host,
            'port' => $this->port,
            'session' => $this->session,
            'seq' => $this->seq,
            'queued' => count($this->queue),
            'in_flight' => $this->transport !== null && $this->transport->busy(),
            'held' => $this->holdKey !== null,
            'hello' => $this->hello,
            'last_status' => $this->lastStatus,
            'stats' => $this->stats,
        ];
    }

    public function log(string $key, string $message): void
    {
        if (isset($this->logged[$key])) {
            return;
        }
        $this->logged[$key] = true;
        $logger = Config::get('logger');
        if ($logger === false) {
            return;
        }
        try {
            is_callable($logger) ? $logger('[gaze] ' . $message) : error_log('[gaze] ' . $message);
        } catch (\Throwable) {
        }
    }

    /** @return list<string> */
    public static function headersFor(string $host, ?string $token): array
    {
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'Expect:',
            'User-Agent: ' . Config::get('client_name') . '/' . (Config::get('client_version') ?? Gaze::VERSION) . ' php/' . PHP_VERSION,
        ];
        if ($token !== null && $token !== '' && !HostResolver::isLoopback($host)) {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        return $headers;
    }

    /**
     * Encodes a batch envelope (exposed for tests and tooling).
     *
     * @param list<array<string, mixed>> $entries
     */
    public function encode(array $entries): string
    {
        $batch = ['v' => 1, 'client' => $this->clientInfo ??= $this->clientInfo(), 'session' => $this->session];
        $first = $entries[0]['trace'] ?? null;
        if ($first !== null) {
            $same = true;
            foreach ($entries as $e) {
                if (($e['trace'] ?? null) !== $first) {
                    $same = false;
                    break;
                }
            }
            if ($same) {
                $batch['trace'] = $first;
                foreach ($entries as $i => $e) {
                    unset($entries[$i]['trace']);
                }
            }
        }
        $batch['entries'] = $entries;
        $json = json_encode($batch, self::JSON_FLAGS);

        return $json === false ? '' : $json;
    }

    private function startNext(): bool
    {
        if ($this->state !== self::UP || !$this->queue || ($this->transport !== null && $this->transport->busy())) {
            return false;
        }
        if ($this->throttleUntil > microtime(true)) {
            return false;
        }
        $entries = [];
        foreach ($this->queue as $key => $entry) {
            if ($key === $this->holdKey) {
                break; // the open chain is always the newest entry
            }
            $entries[] = $entry;
            unset($this->queue[$key]);
            if (($this->latest[$entry['id']] ?? null) === $key) {
                unset($this->latest[$entry['id']]);
            }
            if (count($entries) >= self::MAX_BATCH) {
                break;
            }
        }
        if (!$entries) {
            return false;
        }
        $body = $this->encode($entries);
        if ($body === '') {
            $this->log('encode', 'could not encode a batch: ' . json_last_error_msg());
            $this->stats['dropped'] += count($entries);

            return true;
        }
        $this->inflight = $entries;
        $this->transport()->startPost('/v1/batch', $body, (int) Config::get('timeout_ms'), (float) Config::get('linger_ms'));

        return true;
    }

    /** @param array{status:int, errno:int, error:string, body:string, ms:float} $r */
    private function finish(array $r): void
    {
        $entries = $this->inflight ?? [];
        $this->inflight = null;
        $this->lastStatus = $r['status'];
        $status = $r['status'];

        // A timeout usually means this process was blocked elsewhere (another library's synchronous
        // HTTP call, a slow query) and never read the reply: the app itself answers in milliseconds.
        // Entry ids are client-generated and a repeated `add` acts as an update, so resending is
        // idempotent. Only repeated timeouts count as the app being down.
        if ($r['errno'] === CURLE_OPERATION_TIMEDOUT && $entries && $this->timeoutRetries < 2) {
            ++$this->timeoutRetries;
            $this->requeue($entries);

            return;
        }

        if ($r['errno'] !== 0 || $status === 0 || $status >= 500) {
            ++$this->stats['failures'];
            $this->stats['dropped'] += count($entries);
            if ($status >= 500) {
                $this->log('http-' . $status, 'app answered HTTP ' . $status . '; pausing sends for ' . Config::get('down_ttl') . ' s');
            }
            $this->markDown($r['errno'] !== 0 ? $r['error'] : 'HTTP ' . $status);

            return;
        }
        $this->timeoutRetries = 0;
        if ($status === 429) {
            if (++$this->throttled <= 3) {
                $this->requeue($entries);
                $this->throttleUntil = microtime(true) + 0.2 * $this->throttled;
            } else {
                $this->stats['dropped'] += count($entries);
                $this->log('http-429', 'app is overloaded (HTTP 429); dropping entries');
            }

            return;
        }
        $this->throttled = 0;
        if ($status >= 400) {
            ++$this->stats['failures'];
            $this->stats['dropped'] += count($entries);
            $this->log('http-' . $status, 'app rejected a batch with HTTP ' . $status . ': ' . substr($r['body'], 0, 300));

            return;
        }
        ++$this->stats['batches'];
        $this->stats['entries'] += count($entries);
    }

    /**
     * Put an unacknowledged batch back at the front of the queue, keeping its order.
     *
     * @param list<array<string, mixed>> $entries
     */
    private function requeue(array $entries): void
    {
        $requeued = [];
        foreach ($entries as $entry) {
            $key = $this->nextKey++;
            $requeued[$key] = $entry;
            $this->latest[$entry['id']] ??= $key;
        }
        $this->queue = $requeued + $this->queue;
    }

    private function resolveState(): bool
    {
        try {
            $this->port = (int) Config::get('port');
            $this->host ??= HostResolver::resolve($this->port);
            $key = StateFile::endpointKey($this->host, $this->port);
            $now = time();
            $shared = StateFile::read($key);
            if ($shared !== null && ($shared['down_until'] ?? 0) > $now) {
                $this->state = self::DOWN;
                $this->retryAt = (float) $shared['down_until'];

                return false;
            }
            if ($shared !== null && ($shared['up_until'] ?? 0) > $now) {
                $this->markUp(is_array($shared['hello'] ?? null) ? $shared['hello'] : null);

                return true;
            }

            return $this->handshake($key, $shared);
        } catch (\Throwable $e) {
            $this->log('resolve', 'could not reach the app: ' . $e->getMessage());
            $this->markDown('error');

            return false;
        }
    }

    /** @param array<string, mixed>|null $previous */
    private function handshake(string $key, ?array $previous): bool
    {
        $r = $this->transport()->request('GET', '/v1/hello', null, (int) Config::get('connect_timeout_ms') + 900);
        $hello = $r['errno'] === 0 && $r['status'] === 200 ? json_decode($r['body'], true) : null;
        if (!is_array($hello) || ($hello['v'] ?? null) !== 1) {
            if ($r['errno'] === 0 && $r['status'] !== 0) {
                $this->log('hello', 'GET /v1/hello answered HTTP ' . $r['status'] . ' at ' . $this->transport()->baseUrl() . ' (not a Gaze app?)');
            }
            $this->markDown($r['errno'] !== 0 ? $r['error'] : 'hello HTTP ' . $r['status']);

            return false;
        }
        $min = (int) ($hello['protocol']['min'] ?? 1);
        $max = (int) ($hello['protocol']['max'] ?? 1);
        if ($min > 1 || $max < 1) {
            $this->log('protocol', "the app speaks protocol $min..$max; this client speaks 1");
            $this->markDown('protocol');

            return false;
        }
        $instance = $hello['server']['instance'] ?? null;
        StateFile::write($key, [
            'up_until' => time() + (int) Config::get('hello_ttl'),
            'instance' => $instance,
            'restarted' => $previous !== null && isset($previous['instance']) && $previous['instance'] !== $instance,
            'hello' => $hello,
            'at' => time(),
        ]);
        $this->markUp($hello);

        return true;
    }

    /** @param array<string, mixed>|null $hello */
    private function markUp(?array $hello): void
    {
        $this->state = self::UP;
        $this->hello = $hello;
        if (isset($hello['limits']) && is_array($hello['limits'])) {
            ValueFactory::applyServerLimits($hello['limits']);
        }
    }

    private function markDown(string $reason): void
    {
        $this->state = self::DOWN;
        $ttl = max(1, (int) Config::get('down_ttl'));
        $this->retryAt = microtime(true) + $ttl;
        if ($this->host !== null) {
            StateFile::write(StateFile::endpointKey($this->host, $this->port), [
                'down_until' => time() + $ttl,
                'reason' => $reason,
                'at' => time(),
            ]);
        }
        $this->stats['dropped'] += count($this->queue);
        $this->queue = [];
        $this->latest = [];
        $this->holdKey = $this->holdOwner = null;
        $this->inflight = null;
        $this->transport?->abort();
    }

    private function dropOldest(): void
    {
        $key = array_key_first($this->queue);
        if ($key === null || $key === $this->holdKey) {
            return;
        }
        $id = $this->queue[$key]['id'];
        unset($this->queue[$key]);
        if (($this->latest[$id] ?? null) === $key) {
            unset($this->latest[$id]);
        }
        ++$this->stats['dropped'];
        $this->log('queue', 'queue full (' . self::MAX_QUEUE . ' entries): dropping the oldest');
    }

    private function transport(): CurlTransport
    {
        if ($this->transport === null) {
            $this->port = (int) Config::get('port');
            $this->host ??= HostResolver::resolve($this->port);
            $token = Config::get('token');
            $this->transport = new CurlTransport(
                HostResolver::baseUrl($this->host, $this->port),
                self::headersFor($this->host, is_string($token) ? $token : null),
                (int) Config::get('connect_timeout_ms'),
            );
        }

        return $this->transport;
    }

    /** @return array<string, mixed> */
    private function clientInfo(): array
    {
        $info = [
            'name' => (string) Config::get('client_name'),
            'version' => (string) (Config::get('client_version') ?? Gaze::VERSION),
            'runtime' => 'php',
            'runtime_version' => PHP_VERSION,
        ];
        if (($fw = Config::get('framework')) !== null) {
            $info['framework'] = (string) $fw;
            if (($fwv = Config::get('framework_version')) !== null) {
                $info['framework_version'] = (string) $fwv;
            }
        }
        $host = gethostname();
        if (is_string($host) && $host !== '') {
            $info['host'] = $host;
        }
        $pid = getmypid();
        if (is_int($pid)) {
            $info['pid'] = $pid;
        }
        $project = Config::get('project');
        $project = is_array($project) ? $project : (is_string($project) && $project !== '' ? ['name' => $project] : []);
        if (!isset($project['root'])) {
            try {
                if (class_exists(InstalledVersions::class)) {
                    $root = InstalledVersions::getRootPackage()['install_path'] ?? null;
                    if (is_string($root) && ($real = realpath($root)) !== false) {
                        $project['root'] = $real;
                    }
                }
            } catch (\Throwable) {
            }
        }
        if ($project) {
            $info['project'] = $project;
        }

        return $info;
    }
}
