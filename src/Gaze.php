<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze;

use TheWonderJoy\Gaze\Exceptions\StopExecutionRequested;
use TheWonderJoy\Gaze\Support\Macroable;
use TheWonderJoy\Gaze\Support\Origin;
use TheWonderJoy\Gaze\Support\RateLimiter;
use TheWonderJoy\Gaze\Support\Uuid;
use TheWonderJoy\Gaze\Value\ValueFactory;

/**
 * Fluent API. One instance = one entry in the app (like a Ray instance and its uuid):
 *
 *   gaze($user)->red()->label('user');      // one request: op add with color + label
 *   $g = gaze('counting'); $g->send(3);     // op update on the same id (replaces the body)
 *   $g->large();                            // already sent: op patch
 *
 * Content methods (send, text, table, ...) create the entry or replace its body; modifiers
 * (colors, label, size, hide, expand) change it. Values are converted at call time, so later
 * mutation does not change what was logged.
 */
class Gaze
{
    use Macroable;

    public const VERSION = '0.1.0';

    public const COLORS = ['green', 'orange', 'red', 'purple', 'blue', 'gray'];

    private static ?self $disabled = null;

    private static ?\Closure $factory = null;

    private static ?RateLimiter $rateLimiter = null;

    /** @var array<string, array{id: string, times: int}> */
    private static array $counters = [];

    /** @var array<string, array{max: int, sent: int}> */
    private static array $limiters = [];

    /** @var array<string, array{start: int, last: int, mem: int}> */
    private static array $timers = [];

    /** Entry id; created on the first content call (or set by update()/append()/id()). */
    protected ?string $id = null;

    /** An entry with $id exists in the app (or is queued): the next content call is an update. */
    protected bool $hasEntry = false;

    /** Content calls append iterations to $id instead of replacing it. */
    protected bool $appending = false;

    /** @var array<string, mixed> modifiers set before the first content call */
    protected array $mods = [];

    /** if(false) suppresses every later call on this instance. */
    protected bool $canSend = true;

    protected ?string $limitKey = null;

    // ---------------------------------------------------------------- factory and config

    /** The instance gaze() returns: a shared inert instance when disabled (no allocation, no cloning). */
    public static function make(): static
    {
        Config::all();
        if (!Config::$enabled) {
            if (!self::$disabled instanceof static) {
                self::$disabled = new static();
            }

            return self::$disabled;
        }

        return self::$factory !== null ? (self::$factory)() : new static();
    }

    /** Framework packages return their subclass from gaze(). */
    public static function setFactory(?\Closure $factory): void
    {
        self::$factory = $factory;
    }

    /**
     * Keys: enabled, host, port, token, project (name or [name, root]), limits [depth, items, nodes, string],
     * connect_timeout_ms, timeout_ms, drain_ms, linger_ms, flush_timeout_ms, down_ttl, hello_ttl, host_ttl, state_dir,
     * origin_frames, discover, logger, client_name, client_version, framework, framework_version.
     *
     * @param array<string, mixed> $options
     */
    public static function configure(array $options): void
    {
        Config::set($options);
        ValueFactory::reset();
        $transport = ['host', 'port', 'token', 'connect_timeout_ms', 'timeout_ms', 'state_dir', 'discover', 'probe_timeout_ms'];
        Client::current()?->reconfigure((bool) array_intersect_key($options, array_flip($transport)));
    }

    /** @return array<string, mixed> */
    public static function config(): array
    {
        return Config::all();
    }

    public static function client(): Client
    {
        return Client::instance();
    }

    /** Forgets all process state: configuration overrides, queue, counters, timers, limiters (tests). */
    public static function reset(): void
    {
        Client::reset();
        Config::reset();
        ValueFactory::reset(true);
        self::$disabled = null;
        self::$rateLimiter = null;
        self::$counters = self::$limiters = self::$timers = [];
    }

    /** Opt-in ray() and rd() for code written against spatie/ray. Never shadows existing functions. */
    public static function registerRayHelper(): bool
    {
        if (function_exists('ray') && function_exists('rd')) {
            return false;
        }
        require_once __DIR__ . '/ray-helper.php';

        return true;
    }

    /** Converts any PHP value to an RFC-0001 Value node (for custom() bodies). */
    public static function value(mixed $value): array
    {
        return ValueFactory::from($value);
    }

    /**
     * The app's GET /v1/hello answer (watchers, redact, limits, ...), shared by every process for
     * hello_ttl seconds; null when disabled or when the app is unreachable.
     *
     * @return array<string, mixed>|null
     */
    public static function hello(): ?array
    {
        Config::all();
        if (!Config::$enabled) {
            return null;
        }
        $client = Client::instance();
        if (!$client->accepting()) {
            return null;
        }
        $hello = $client->status()['hello'] ?? null;

        return is_array($hello) ? $hello : null;
    }

    public static function rateLimiter(): RateLimiter
    {
        return self::$rateLimiter ??= new RateLimiter();
    }

    // ---------------------------------------------------------------- trace context

    /** @return array<string, mixed>|null */
    public static function traceContext(): ?array
    {
        return Client::current()?->traceContext();
    }

    /**
     * Sets the unit of work for the following entries, e.g.
     * ['id' => <32 hex>, 'kind' => 'http', 'name' => 'GET /orders/5'] (null clears it).
     *
     * @param array<string, mixed>|null $trace
     */
    public static function setTraceContext(?array $trace): void
    {
        if ($trace !== null) {
            if (!preg_match('/^[0-9a-f]{32}$/', (string) ($trace['id'] ?? ''))) {
                $trace['id'] = Uuid::traceId();
            }
            if (isset($trace['kind'])) {
                $trace['kind'] = self::traceKind((string) $trace['kind']);
            }
            if (isset($trace['parent']) && !preg_match('/^[0-9a-f]{16}$|^[0-9a-f]{32}$/', (string) $trace['parent'])) {
                unset($trace['parent']);
            }
        }
        Client::instance()->setTrace($trace);
    }

    /** Starts a trace (kind http, job, command, test, schedule, browser, livewire, inertia, other). */
    public static function startTrace(string $name, string $kind = 'other', ?string $parent = null): string
    {
        $trace = ['id' => Uuid::traceId(), 'kind' => self::traceKind($kind), 'name' => $name, 'started_at' => round(microtime(true) * 1000, 3)];
        if ($parent !== null && preg_match('/^[0-9a-f]{16}$|^[0-9a-f]{32}$/', $parent)) {
            $trace['parent'] = $parent;
        }
        Client::instance()->setTrace($trace);

        return $trace['id'];
    }

    /** Continues a W3C traceparent (00-<trace-id>-<span-id>-<flags>) from an incoming request. */
    public static function continueTrace(string $traceparent, string $name, string $kind = 'http'): ?string
    {
        if (!preg_match('/^[0-9a-f]{2}-([0-9a-f]{32})-([0-9a-f]{16})-[0-9a-f]{2}$/', strtolower(trim($traceparent)), $m)) {
            return null;
        }
        Client::instance()->setTrace(['id' => $m[1], 'parent' => $m[2], 'kind' => self::traceKind($kind), 'name' => $name, 'started_at' => round(microtime(true) * 1000, 3)]);

        return $m[1];
    }

    /** W3C traceparent header value for outgoing calls (null without a trace). */
    public static function traceparent(): ?string
    {
        $trace = Client::current()?->traceContext();

        return $trace === null ? null : '00-' . $trace['id'] . '-' . Uuid::spanId() . '-01';
    }

    /**
     * Groups the entries logged inside $callback under a child trace; returns the callback's result.
     * Without a callback the group stays open until groupEnd().
     */
    public function group(string $name, ?\Closure $callback = null): mixed
    {
        Config::all();
        $opened = Config::$enabled;
        if ($opened) {
            $client = Client::instance();
            $trace = ['id' => Uuid::traceId(), 'kind' => 'other', 'name' => $name, 'started_at' => round(microtime(true) * 1000, 3)];
            if (($parent = $client->traceContext()) !== null) {
                $trace['parent'] = $parent['id'];
            }
            $client->pushTrace($trace);
        }
        if ($callback === null) {
            return $this;
        }
        try {
            return $callback($this);
        } finally {
            if ($opened) {
                $this->groupEnd();
            }
        }
    }

    /** Closes the innermost group() and sends its summary entry (kind "group", trace.ended = true). */
    public function groupEnd(): static
    {
        $client = Client::current();
        $trace = $client?->popTrace();
        if ($trace === null || !$this->ready()) {
            return $this;
        }
        $body = ['name' => (string) ($trace['name'] ?? ''), 'duration_ms' => round(microtime(true) * 1000 - (float) ($trace['started_at'] ?? 0), 3)];
        $client->enqueue(['op' => 'add', 'id' => Uuid::v7(), 'ts' => self::now(), 'kind' => 'group', 'body' => $body, 'trace' => ['ended' => true] + $trace]);
        $client->pump(true);

        return $this;
    }

    // ---------------------------------------------------------------- content

    public function send(mixed ...$values): static
    {
        if (!$values || !$this->ready()) {
            return $this;
        }
        $converted = [];
        foreach ($values as $value) {
            if ($value instanceof \Closure) {
                try {
                    $value = $value($this);
                } catch (\TypeError) {
                    $converted[] = ValueFactory::from($value);
                    continue;
                }
                if ($value instanceof self) {
                    continue;
                }
            }
            $converted[] = ValueFactory::from($value);
        }

        return $converted ? $this->emit('log', ['values' => $converted]) : $this;
    }

    /** Like send(), but closures are shown as values instead of being called. */
    public function raw(mixed ...$values): static
    {
        if (!$values || !$this->ready()) {
            return $this;
        }

        return $this->emit('log', ['values' => array_map(ValueFactory::from(...), array_values($values))]);
    }

    /** Sends $value and returns it unchanged: return gaze()->pass($x); */
    public function pass(mixed $value): mixed
    {
        $this->send($value);

        return $value;
    }

    public function text(string $text): static
    {
        return $this->ready() ? $this->emit('text', ['text' => $text]) : $this;
    }

    /** Rendered in a sandboxed iframe (no scripts) by the app. */
    public function html(string $html = ''): static
    {
        return $this->ready() ? $this->emit('html', ['html' => $html]) : $this;
    }

    public function markdown(string $markdown): static
    {
        return $this->ready() ? $this->emit('markdown', ['markdown' => $markdown]) : $this;
    }

    public function mermaid(string $source): static
    {
        return $this->ready() ? $this->emit('mermaid', ['source' => $source]) : $this;
    }

    /**
     * JSON view of values: JSON strings are decoded, anything else is shown as json_encode() sees it
     * (JsonSerializable output, public properties). Covers spatie/ray's json() and toJson().
     */
    public function json(mixed ...$values): static
    {
        if (!$values || !$this->ready()) {
            return $this;
        }
        $nodes = [];
        foreach ($values as $value) {
            if (is_string($value)) {
                $decoded = json_decode($value, false, 512, JSON_BIGINT_AS_STRING);
                $nodes[] = json_last_error() === JSON_ERROR_NONE ? ValueFactory::fromPlain($decoded) : ValueFactory::string($value);
                continue;
            }
            $json = json_encode($value, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION);
            $nodes[] = $json === false ? ValueFactory::from($value) : ValueFactory::fromPlain(json_decode($json, false, 512, JSON_BIGINT_AS_STRING));
        }
        $value = count($nodes) === 1 ? $nodes[0] : ['k' => 'list', 'count' => count($nodes), 'items' => $nodes];

        return $this->emit('json', ['value' => $value]);
    }

    public function toJson(mixed ...$values): static
    {
        return $this->json(...$values);
    }

    public function xml(string $xml): static
    {
        if (!$this->ready()) {
            return $this;
        }
        $pretty = $xml;
        if (class_exists(\DOMDocument::class)) {
            $dom = new \DOMDocument('1.0');
            $dom->preserveWhiteSpace = false;
            $dom->formatOutput = true;
            if (@$dom->loadXML($xml, LIBXML_NONET) && ($out = $dom->saveXML()) !== false) {
                $pretty = $out;
            }
        }

        return $this->emit('text', ['text' => $pretty, 'lang' => 'xml'], ['label' => 'XML']);
    }

    /**
     * Key/value table, or a records table (columns + rows) for a list of arrays.
     *
     * @param array<array-key, mixed>|object $values
     */
    public function table(array|object $values, string $label = 'Table'): static
    {
        if (!$this->ready()) {
            return $this;
        }
        if (is_object($values)) {
            $values = $values instanceof \Traversable ? iterator_to_array($values) : get_object_vars($values);
        }
        $max = (int) (Config::get('limits')['items'] ?? 200);
        $count = count($values);
        if ($count > 0 && array_is_list($values) && self::allArrays($values)) {
            $columns = [];
            foreach (array_slice($values, 0, $max) as $row) {
                foreach ($row as $k => $_) {
                    if (count($columns) < 50) {
                        $columns[(string) $k] = true;
                    }
                }
            }
            $rows = [];
            foreach (array_slice($values, 0, $max) as $row) {
                $cells = [];
                foreach ($columns as $column => $_) {
                    $cells[] = array_key_exists($column, $row) ? ValueFactory::from($row[$column]) : ['k' => 'undef'];
                }
                $rows[] = $cells;
            }
            $body = ['columns' => array_map('strval', array_keys($columns)), 'rows' => $rows];
        } else {
            $entries = [];
            foreach ($values as $k => $v) {
                if (count($entries) >= $max) {
                    break;
                }
                $entries[] = [(string) $k, ValueFactory::from($v)];
            }
            $body = ['entries' => $entries];
        }
        if ($count > $max) {
            $body['cut'] = $count - $max;
        }

        return $this->emit('table', $body, ['label' => self::clip($label)]);
    }

    /** Local files are inlined as a data: URI (up to 5 MiB); URLs are passed through. */
    public function image(string $location, ?string $alt = null): static
    {
        if (!$this->ready()) {
            return $this;
        }
        $body = ['src' => $location];
        if (!preg_match('~^(https?|data|blob):~i', $location) && @is_file($location) && @is_readable($location)) {
            $size = (int) @filesize($location);
            $real = realpath($location);
            $body['path'] = $real === false ? $location : $real;
            if ($size <= 5 * 1024 * 1024 && ($bytes = @file_get_contents($location)) !== false) {
                $body['src'] = 'data:' . self::imageMime($location, $bytes) . ';base64,' . base64_encode($bytes);
            } else {
                $body['src'] = 'file:///' . ltrim(str_replace('\\', '/', $body['path']), '/');
            }
        }
        if ($alt !== null) {
            $body['alt'] = $alt;
        }

        return $this->emit('image', $body, ['label' => 'Image']);
    }

    /** File contents as text (first 1 MiB), labelled with the file name. */
    public function file(string $filename): static
    {
        if (!$this->ready()) {
            return $this;
        }
        $name = basename($filename);
        if (!@is_file($filename) || !@is_readable($filename)) {
            return $this->emit('text', ['text' => "File not found: '{$filename}'", 'filename' => $name], ['label' => self::clip($name)]);
        }
        $max = 1024 * 1024;
        $size = (int) @filesize($filename);
        $text = (string) @file_get_contents($filename, false, null, 0, $max);
        $real = realpath($filename);
        $body = ['text' => $text, 'filename' => $name, 'path' => $real === false ? $filename : $real];
        if ($size > $max) {
            $body['cut'] = $size - $max;
        }

        return $this->emit('text', $body, ['label' => self::clip($name)]);
    }

    /** A link; a missing scheme becomes https:// (no illuminate/support needed). */
    public function url(string $url, string $label = ''): static
    {
        if (!preg_match('~^[a-z][a-z0-9+.-]*:~i', $url)) {
            $url = 'https://' . ltrim($url, '/');
        }
        $label = $label !== '' ? $label : $url;

        return $this->html('<a href="' . htmlspecialchars($url, ENT_QUOTES) . '">' . htmlspecialchars($label, ENT_QUOTES) . '</a>');
    }

    public function link(string $url, string $label = ''): static
    {
        return $this->url($url, $label);
    }

    public function date(?\DateTimeInterface $date, string $format = 'Y-m-d H:i:s'): static
    {
        if (!$this->ready()) {
            return $this;
        }
        $body = $date === null
            ? ['formatted' => null, 'timestamp' => null, 'timezone' => null]
            : [
                'formatted' => $date->format($format),
                'timestamp' => $date->getTimestamp(),
                'timezone' => $date->getTimezone()->getName(),
                'iso' => $date->format($date->format('u') === '000000' ? 'Y-m-d\TH:i:sP' : 'Y-m-d\TH:i:s.uP'),
            ];

        return $this->emit('date', $body);
    }

    /** spatie/ray name for date(); accepts any DateTimeInterface (Carbon included). */
    public function carbon(?\DateTimeInterface $date): static
    {
        return $this->date($date);
    }

    public function className(object|string $object): static
    {
        return $this->send(is_object($object) ? $object::class : $object);
    }

    public function separator(): static
    {
        return $this->ready() ? $this->emit('separator', new \stdClass()) : $this;
    }

    /**
     * Any entry kind (^[a-z][a-z0-9_.-]*$) with a ready-made body; values inside the body must
     * already be Value nodes (Gaze::value()). For framework packages: request, job, query, ...
     * $modifiers may also carry "origin" (replaces the captured call site), "trace" and "tags".
     *
     * @param array<string, mixed>|\stdClass $body
     * @param array<string, mixed> $modifiers
     */
    public function custom(string $kind, array|\stdClass $body, array $modifiers = []): static
    {
        if (!$this->ready()) {
            return $this;
        }
        if (!preg_match('/^[a-z][a-z0-9_.-]{0,63}$/', $kind)) {
            $kind = substr('x' . preg_replace('/[^a-z0-9_.-]+/', '_', strtolower($kind)), 0, 64);
        }

        return $this->emit($kind, $body === [] ? new \stdClass() : $body, $modifiers);
    }

    // ---------------------------------------------------------------- explicit ids

    /** The entry id (UUIDv7), created now if needed; use it with update()/append() later. */
    public function id(): string
    {
        return $this->id ??= Uuid::v7();
    }

    /** Targets an existing entry: the next content call (or $values) replaces its body. */
    public function update(string $id, mixed ...$values): static
    {
        $this->id = self::normalizeId($id);
        $this->hasEntry = true;
        $this->appending = false;

        return $values ? $this->send(...$values) : $this;
    }

    /** Targets an existing entry: content calls add iterations to it (op append). */
    public function append(string $id, mixed ...$values): static
    {
        $this->id = self::normalizeId($id);
        $this->hasEntry = true;
        $this->appending = true;

        return $values ? $this->send(...$values) : $this;
    }

    // ---------------------------------------------------------------- modifiers

    public function color(string $color): static
    {
        $color = strtolower($color) === 'grey' ? 'gray' : strtolower($color);

        return in_array($color, self::COLORS, true) ? $this->modify(['color' => $color]) : $this;
    }

    public function green(): static
    {
        return $this->modify(['color' => 'green']);
    }

    public function orange(): static
    {
        return $this->modify(['color' => 'orange']);
    }

    public function red(): static
    {
        return $this->modify(['color' => 'red']);
    }

    public function purple(): static
    {
        return $this->modify(['color' => 'purple']);
    }

    public function blue(): static
    {
        return $this->modify(['color' => 'blue']);
    }

    public function gray(): static
    {
        return $this->modify(['color' => 'gray']);
    }

    public function grey(): static
    {
        return $this->modify(['color' => 'gray']);
    }

    public function small(): static
    {
        return $this->modify(['size' => 'sm']);
    }

    public function large(): static
    {
        return $this->modify(['size' => 'lg']);
    }

    /** sm|small, md|normal, lg|large */
    public function size(string $size): static
    {
        $size = match (strtolower($size)) {
            'sm', 'small' => 'sm',
            'lg', 'large' => 'lg',
            default => 'md',
        };

        return $this->modify(['size' => $size]);
    }

    public function label(string $label): static
    {
        return $this->modify(['label' => self::clip($label)]);
    }

    /** Collapsed in the app. */
    public function hide(): static
    {
        return $this->modify(['hidden' => true]);
    }

    /** expand() = level 1, expand(3) = three levels, expand('user', 'user.roles') = paths. */
    public function expand(int|string ...$levelOrKey): static
    {
        $expand = [];
        foreach ($levelOrKey ?: [1] as $item) {
            if (is_int($item)) {
                $expand['level'] = max($expand['level'] ?? 0, max(0, $item));
            } else {
                $expand['paths'][] = $item;
            }
        }

        return $this->modify(['expand' => $expand]);
    }

    public function expandAll(): static
    {
        return $this->modify(['expand' => ['level' => 999]]);
    }

    public function remove(): static
    {
        Config::all();
        if (!Config::$enabled || !$this->canSend || $this->id === null || !$this->hasEntry) {
            return $this;
        }
        $client = Client::instance();
        $owner = spl_object_id($this);
        $client->touch($owner);
        $client->release($owner);
        if ($client->accepting()) {
            $client->enqueue(['op' => 'remove', 'id' => $this->id, 'ts' => self::now()]);
            $client->pump(true);
        }

        return $this;
    }

    /** @deprecated spatie/ray compatibility; prefer if(). */
    public function showWhen(bool|callable $condition): static
    {
        return self::truthy($condition) ? $this : $this->remove();
    }

    /** @deprecated spatie/ray compatibility; prefer if(). */
    public function showIf(bool|callable $condition): static
    {
        return $this->showWhen($condition);
    }

    /** @deprecated spatie/ray compatibility; prefer if(). */
    public function removeWhen(bool|callable $condition): static
    {
        return self::truthy($condition) ? $this->remove() : $this;
    }

    /** @deprecated spatie/ray compatibility; prefer if(). */
    public function removeIf(bool|callable $condition): static
    {
        return $this->removeWhen($condition);
    }

    // ---------------------------------------------------------------- screens and app commands

    public function newScreen(string $name = ''): static
    {
        if (str_starts_with($name, '__pest_evaluable_')) {
            $name = str_replace('_', ' ', substr($name, 17));
        }

        return $this->command('new_screen', ['name' => $name]);
    }

    public function clearScreen(): static
    {
        return $this->newScreen();
    }

    public function clearAll(): static
    {
        return $this->command('clear');
    }

    public function screenColor(string $color): static
    {
        $color = strtolower($color) === 'grey' ? 'gray' : strtolower($color);

        return in_array($color, self::COLORS, true) ? $this->command('screen_color', ['color' => $color]) : $this;
    }

    public function showApp(): static
    {
        return $this->command('show_app');
    }

    public function hideApp(): static
    {
        return $this->command('hide_app');
    }

    public function notify(string $text): static
    {
        return $this->command('notify', ['text' => $text]);
    }

    public function confetti(): static
    {
        return $this->command('confetti');
    }

    /** Sets client.project.name for the following batches of this process. */
    public function project(string $name): static
    {
        $project = Config::get('project');
        self::configure(['project' => is_array($project) ? ['name' => $name] + $project : ['name' => $name]]);

        return $this;
    }

    // ---------------------------------------------------------------- debugging helpers

    /** Stopwatch: first call starts the timer, later calls report total and lap time. A closure is timed. */
    public function measure(string|\Closure $name = 'default'): static
    {
        if ($name instanceof \Closure) {
            $start = hrtime(true);
            $mem = memory_get_usage();
            $name();
            $ms = round((hrtime(true) - $start) / 1e6, 3);
            if (!$this->ready()) {
                return $this;
            }

            return $this->emit('measure', ['name' => 'closure', 'is_new' => true, 'total_ms' => $ms, 'since_last_ms' => $ms, 'mem_peak' => memory_get_peak_usage(), 'mem_delta' => memory_get_usage() - $mem]);
        }
        Config::all();
        if (!Config::$enabled) {
            return $this;
        }
        $now = hrtime(true);
        $mem = memory_get_usage();
        if (!isset(self::$timers[$name])) {
            self::$timers[$name] = ['start' => $now, 'last' => $now, 'mem' => $mem];
            $body = ['name' => $name, 'is_new' => true, 'total_ms' => 0, 'since_last_ms' => 0, 'mem_peak' => memory_get_peak_usage(), 'mem_delta' => 0];
        } else {
            $timer = self::$timers[$name];
            $body = [
                'name' => $name,
                'is_new' => false,
                'total_ms' => round(($now - $timer['start']) / 1e6, 3),
                'since_last_ms' => round(($now - $timer['last']) / 1e6, 3),
                'mem_peak' => memory_get_peak_usage(),
                'mem_delta' => $mem - $timer['mem'],
            ];
            self::$timers[$name] = ['start' => $timer['start'], 'last' => $now, 'mem' => $mem];
        }

        return $this->ready() ? $this->emit('measure', $body) : $this;
    }

    /** Forgets one stopwatch, or all of them. */
    public function stopTime(string $name = ''): static
    {
        if ($name === '') {
            self::$timers = [];
        } else {
            unset(self::$timers[$name]);
        }

        return $this;
    }

    /**
     * Stack trace from the call site. $startingFromFrame(array $frame): bool picks the first frame.
     */
    public function trace(?\Closure $startingFromFrame = null, ?int $offset = null, ?int $limit = null): static
    {
        if (!$this->ready()) {
            return $this;
        }
        $frames = Origin::backtrace(100);
        if ($startingFromFrame !== null) {
            foreach ($frames as $i => $frame) {
                if ($startingFromFrame($frame)) {
                    $frames = array_slice($frames, $i);
                    break;
                }
            }
        }
        if ($offset) {
            $frames = array_slice($frames, $offset);
        }
        if ($limit) {
            $frames = array_slice($frames, 0, $limit);
        }

        return $this->emit('trace', ['frames' => array_values($frames)]);
    }

    public function backtrace(?\Closure $startingFromFrame = null, ?int $offset = null, ?int $limit = null): static
    {
        return $this->trace($startingFromFrame, $offset, $limit);
    }

    /** The frame that called the function containing this call (empty at the top level). */
    public function caller(): static
    {
        if (!$this->ready()) {
            return $this;
        }
        $frames = Origin::backtrace(3);

        return $this->emit('trace', ['frames' => isset($frames[1]) ? [$frames[1]] : []], ['label' => 'Caller']);
    }

    /** @param array<array-key, mixed> $meta shown as the exception's context */
    public function exception(\Throwable $exception, array $meta = []): static
    {
        if (!$this->ready()) {
            return $this;
        }
        $body = self::exceptionBody($exception, 0);
        if ($meta) {
            $body['context'] = ValueFactory::from($meta);
        }

        return $this->emit('exception', $body, ['color' => 'red']);
    }

    public function phpinfo(string ...$properties): static
    {
        if (!$this->ready()) {
            return $this;
        }
        $rows = [];
        if (!$properties) {
            $rows = [
                'PHP version' => PHP_VERSION,
                'Memory limit' => ini_get('memory_limit'),
                'Max execution time' => ini_get('max_execution_time'),
                'Upload max filesize' => ini_get('upload_max_filesize'),
                'Post max size' => ini_get('post_max_size'),
                'Max file uploads' => ini_get('max_file_uploads'),
                'Display errors' => ini_get('display_errors'),
                'Error reporting' => error_reporting(),
                'php.ini file' => php_ini_loaded_file(),
                'php.ini scanned files' => php_ini_scanned_files(),
                'Extensions' => implode(', ', get_loaded_extensions()),
            ];
        } else {
            foreach ($properties as $property) {
                $rows[$property] = ini_get($property);
            }
        }
        $entries = [];
        foreach ($rows as $key => $value) {
            $entries[] = [(string) $key, $value === false ? ['k' => 'const', 'v' => 'not set'] : ValueFactory::from($value)];
        }

        return $this->emit('table', ['entries' => $entries], ['label' => 'PHPInfo']);
    }

    /**
     * Counts calls per name (or per call site) in one entry that is updated in place.
     * Returns the counter's instance, so modifiers apply to the count entry.
     */
    public function count(?string $name = null): static
    {
        if (!$this->ready()) {
            return $this;
        }
        $key = $name ?? self::fingerprint();
        $counter = self::$counters[$key] ?? ['id' => Uuid::v7(), 'times' => 0];
        ++$counter['times'];
        self::$counters[$key] = $counter;
        $text = 'Called ' . ($name !== null ? "`{$name}` " : '') . $counter['times'] . ($counter['times'] === 1 ? ' time.' : ' times.');

        $instance = new static();
        $instance->id = $counter['id'];
        $instance->hasEntry = $counter['times'] > 1;

        return $instance->emit('text', ['text' => $text], ['label' => 'Count']);
    }

    public function counterValue(string $name): int
    {
        return self::$counters[$name]['times'] ?? 0;
    }

    public function clearCounters(): static
    {
        self::$counters = [];

        return $this;
    }

    /** Sends from this call site at most $count times per process ($count <= 0: unlimited). */
    public function limit(int $count): static
    {
        Config::all();
        if (!Config::$enabled) {
            return $this;
        }
        $this->limitKey = self::fingerprint();
        self::$limiters[$this->limitKey] ??= ['max' => $count, 'sent' => 0];

        return $this;
    }

    public function once(mixed ...$values): static
    {
        $this->limit(1);

        return $values ? $this->send(...$values) : $this;
    }

    /**
     * if($condition): later calls on this instance only send when it is truthy.
     * if($condition, $callback): runs $callback($this) when truthy.
     */
    public function if(bool|callable $condition, ?callable $callback = null): static
    {
        $condition = self::truthy($condition);
        if ($callback === null) {
            $this->canSend = $condition;
        } elseif ($condition) {
            $callback($this);
        }

        return $this;
    }

    public function enable(): static
    {
        Config::set(['enabled' => true]);

        return $this;
    }

    public function disable(): static
    {
        Config::set(['enabled' => false]);

        return $this;
    }

    public function enabled(): bool
    {
        Config::all();

        return Config::$enabled;
    }

    public function disabled(): bool
    {
        return !$this->enabled();
    }

    /** Synchronously sends everything queued (waits for the app's answer, flush_timeout_ms at most). */
    public function flush(): static
    {
        Client::current()?->flush();

        return $this;
    }

    /**
     * Shows a lock entry and blocks until "Continue" (or "Stop execution", which throws) in the app.
     * Returns at once when the app is unreachable.
     *
     * @throws StopExecutionRequested
     */
    public function pause(): static
    {
        if (!$this->ready()) {
            return $this;
        }
        $id = Uuid::v7();
        $entry = ['op' => 'add', 'id' => $id, 'ts' => self::now(), 'kind' => 'lock', 'body' => ['name' => $id]];
        [$origin] = Origin::capture(0);
        if ($origin !== null) {
            $entry['origin'] = $origin;
        }
        $limit = (int) ini_get('max_execution_time');
        if ($limit > 0) {
            @set_time_limit(0); // Windows counts wall time: a pause would hit the limit
        }
        try {
            $state = Client::instance()->lock($entry);
        } finally {
            if ($limit > 0) {
                @set_time_limit($limit);
            }
        }
        if ($state === 'stopped') {
            throw StopExecutionRequested::make();
        }

        return $this;
    }

    /** Flushes synchronously, then exits. */
    public function die(int|string $status = ''): never
    {
        Client::current()?->flush();
        exit($status);
    }

    // ---------------------------------------------------------------- internals

    public function __destruct()
    {
        if ($this->id !== null) {
            try {
                Client::current()?->closeChain(spl_object_id($this));
            } catch (\Throwable) {
            }
        }
    }

    /** Guard for every sending method: enabled, if(), reachable, limit()/once(), rate limiter. */
    protected function ready(): bool
    {
        Config::all();
        if (!Config::$enabled || !$this->canSend) {
            return false;
        }
        $client = Client::instance();
        $client->touch(spl_object_id($this));
        if (!$client->accepting()) {
            return false;
        }
        if ($this->limitKey !== null) {
            $limiter = self::$limiters[$this->limitKey] ?? null;
            if ($limiter !== null && $limiter['max'] > 0) {
                if ($limiter['sent'] >= $limiter['max']) {
                    return false;
                }
                ++self::$limiters[$this->limitKey]['sent'];
            }
        }
        if (self::$rateLimiter !== null && self::$rateLimiter->reached()) {
            if (!self::$rateLimiter->isNotified()) {
                self::$rateLimiter->notify();
                $client->enqueue([
                    'op' => 'add', 'id' => Uuid::v7(), 'ts' => self::now(), 'kind' => 'text',
                    'body' => ['text' => 'Rate limit has been reached...'], 'label' => 'Rate limit', 'color' => 'orange',
                ]);
                $client->pump(true);
            }

            return false;
        }

        return true;
    }

    /**
     * Creates the entry (op add), replaces its body (op update) or adds an iteration (op append).
     * A new add is held until this instance's chain ends, so chained modifiers fold into it.
     *
     * @param array<string, mixed>|\stdClass $body
     * @param array<string, mixed> $modifiers
     */
    protected function emit(string $kind, array|\stdClass $body, array $modifiers = []): static
    {
        $client = Client::instance();
        $owner = spl_object_id($this);
        if ($this->id === null) {
            $this->id = Uuid::v7();
            $op = 'add';
        } elseif ($this->appending) {
            $op = 'append';
        } else {
            $op = $this->hasEntry ? 'update' : 'add';
        }
        $client->release($owner);

        $entry = ['op' => $op, 'id' => $this->id, 'ts' => self::now(), 'kind' => $kind, 'body' => $body];
        foreach ($this->mods as $name => $value) {
            $entry[$name] = $value;
        }
        $this->mods = [];
        foreach ($modifiers as $name => $value) {
            $entry[$name] = $value;
        }
        if (!isset($entry['origin'])) { // framework packages may pass their own origin (custom($kind, $body, ['origin' => ...]))
            [$origin] = Origin::capture((int) Config::get('origin_frames'));
            if ($origin !== null) {
                $entry['origin'] = $origin;
            }
        }
        $this->hasEntry = true;
        $client->enqueue($entry, $owner, $op === 'add');
        self::$rateLimiter?->hit();
        if ($op !== 'add') {
            $client->pump(true); // a held add is pumped when its chain ends (__destruct, next instance, flush)
        }

        return $this;
    }

    /** @param array<string, mixed> $mods */
    protected function modify(array $mods): static
    {
        Config::all();
        if (!Config::$enabled || !$this->canSend) {
            return $this;
        }
        if ($this->id === null || !$this->hasEntry) {
            foreach ($mods as $name => $value) {
                $this->mods[$name] = $value;
            }

            return $this;
        }
        $client = Client::instance();
        $client->touch(spl_object_id($this));
        if ($client->fold($this->id, $mods)) {
            return $this; // merged into an entry that is still queued: nothing new to send
        }
        if ($client->accepting()) {
            $client->enqueue(['op' => 'patch', 'id' => $this->id, 'ts' => self::now()] + $mods);
            $client->pump(true);
        }

        return $this;
    }

    /** @param array<string, mixed> $fields */
    protected function command(string $cmd, array $fields = []): static
    {
        if (!$this->ready()) {
            return $this;
        }
        $client = Client::instance();
        $client->enqueue(['op' => 'add', 'id' => Uuid::v7(), 'ts' => self::now(), 'kind' => 'cmd', 'body' => ['cmd' => $cmd] + $fields]);
        $client->pump(true);

        return $this;
    }

    protected static function now(): float
    {
        return round(microtime(true) * 1000, 3);
    }

    /** @return array<string, mixed> */
    protected static function exceptionBody(\Throwable $e, int $depth): array
    {
        $code = $e->getCode();
        $body = [
            'class' => $e::class,
            'message' => $e->getMessage(),
            'code' => is_int($code) ? $code : (string) $code,
            'frames' => Origin::throwableFrames($e, 100, $depth === 0 ? 3 : 0),
        ];
        $previous = $e->getPrevious();
        if ($previous !== null && $depth < 5) {
            $body['previous'] = self::exceptionBody($previous, $depth + 1);
        }

        return $body;
    }

    private static function fingerprint(): string
    {
        [$origin] = Origin::capture(0);

        return ($origin['file'] ?? '?') . ':' . ($origin['line'] ?? 0);
    }

    private static function traceKind(string $kind): string
    {
        return in_array($kind, ['http', 'job', 'command', 'test', 'schedule', 'browser', 'livewire', 'inertia', 'other'], true) ? $kind : 'other';
    }

    private static function truthy(bool|callable $condition): bool
    {
        return is_callable($condition) ? (bool) $condition() : $condition;
    }

    private static function clip(string $text, int $max = 200): string
    {
        return mb_strlen($text, 'UTF-8') > $max ? mb_substr($text, 0, $max - 1, 'UTF-8') . '…' : $text;
    }

    private static function normalizeId(string $id): string
    {
        $len = strlen($id);
        if ($len >= 8 && $len <= 64) {
            return $id;
        }

        return $len < 8 ? str_pad($id, 8, '_') : md5($id);
    }

    /** @param array<array-key, mixed> $values */
    private static function allArrays(array $values): bool
    {
        foreach ($values as $value) {
            if (!is_array($value)) {
                return false;
            }
        }

        return true;
    }

    private static function imageMime(string $path, string $bytes): string
    {
        $map = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp',
            'svg' => 'image/svg+xml', 'bmp' => 'image/bmp', 'ico' => 'image/x-icon', 'avif' => 'image/avif'];
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (isset($map[$ext])) {
            return $map[$ext];
        }
        if (function_exists('finfo_buffer')) {
            $mime = @finfo_buffer(finfo_open(FILEINFO_MIME_TYPE), $bytes);
            if (is_string($mime) && $mime !== '') {
                return $mime;
            }
        }

        return 'application/octet-stream';
    }
}
