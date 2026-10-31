# gaze-php internals

How the client works inside. For using it, see [README.md](README.md).

## How entries map to the wire

It speaks Gaze's native protocol: `POST /v1/batch`, spec in the monorepo's `protocol/RFC-0001-native-protocol.md`. One `Gaze` instance is one entry, as in Ray (one instance, one uuid).

| You write | On the wire |
|---|---|
| `gaze($x)->red()->label('l')` | one `add` entry carrying `color` and `label` |
| `$g = gaze($x); …; $g->send($y)` | `add`, then `update` on the same id (body replaced) |
| `$g->blue()` after the entry was sent | `patch` (modifiers only) |
| `gaze()->count()` in a loop | one entry, updated with "Called N times." |
| `gaze()->update($id)->html($v2)` / `->append($id)->html($v3)` | `update` / `append` (new iteration) on an explicit id |
| `$g->remove()`, `showWhen(false)`, `removeIf(true)` | `remove` |
| `newScreen()`, `clearAll()`, `notify()`, `confetti()`, `showApp()` … | `cmd` entries with their own ids |

A new entry is held back until its chain ends, so chained modifiers fold into it. The chain ends when the instance is destroyed (the end of the statement for `gaze(...)->red();`), when another instance is used, on `flush()` and at shutdown. An instance kept in a variable is sent on the next Gaze call, on `->flush()`, or when the variable goes out of scope.

Content details: `json` / `toJson` are merged (JSON strings are decoded, other values shown as `json_encode` sees them). `image` inlines local files as `data:` URIs up to 5 MiB. `url` / `link` need no `illuminate/support`. `caller` is safe at the top level. `Gaze::rateLimiter()` exists before the first call.

## Transport

- **Async-immediate.** Each call enqueues. If no request is in flight, a non-blocking `curl_multi` POST starts at once. While one is in flight, entries coalesce into the next batch. At most one request is in flight and one keep-alive connection is reused.
- **Linger.** At the end of a call, entries queued behind a request in flight get up to `linger_ms` (2) for it to finish, so `gaze('a'); gaze('b'); sleep(10);` shows both before the sleep. Set `linger_ms` to `0` for maximum throughput in hot loops.
- **Synchronous** for `flush()`, `pause()`, `gaze_dd()` / `die()`. A shutdown function drains what is left (at most `drain_ms`, 250) after `exit`, fatal errors, time-outs and out-of-memory errors. Under FPM it runs after `fastcgi_finish_request()`.
- **Handshake.** `GET /v1/hello` on the first call of a process, shared by every process through a temp file for 60 s. A changed `server.instance` is recorded. Anything other than a v1 hello (for example the Ray app on the same port) counts as down.
- **App closed.** The connect timeout is 100 ms (Windows does not refuse a closed loopback port, it times out). The "down" result goes to a temp file keyed by host:port for 30 s, so every other process skips the network. 4xx answers (except 429) drop the batch and log once. 5xx and connection errors mark the app down. 429 retries the batch. A read timeout on a request already in flight is retried twice (entry ids make that safe) before the app is marked down.
- **Host discovery** (no host configured): `127.0.0.1` → `host.docker.internal` (when `/.dockerenv` exists or `LARAVEL_SAIL` is set) → the default gateway from `/proc/net/route` (WSL2 NAT, containers). Candidates are probed in parallel (`GET /v1/hello`, 150 ms connect timeout) and the winner is cached for 60 s. `127.0.0.1` rather than `localhost`: on Windows, `localhost` tries `::1` first and a closed IPv6 port times out instead of refusing.
- The token (`GAZE_TOKEN`) is sent as `Authorization: Bearer` to non-loopback hosts only. A `ray.php` file is never read.

Precedence per key: `Gaze::configure()`, then the environment, then the default. `enabled` reads `GAZE_ENABLED`; when it is unset, the default is off if `APP_ENV` is `production` or `prod` (any case), and on otherwise. gaze-laravel always passes `enabled` explicitly, so Laravel's own rule (`! app()->isProduction()`) decides there.

All config keys: `host`, `port`, `token`, `enabled`, `project`, `limits`, `connect_timeout_ms` (100), `timeout_ms` (2000), `linger_ms` (2), `drain_ms` (250), `flush_timeout_ms` (2000), `down_ttl` (30), `hello_ttl` (60), `host_ttl` (60), `probe_timeout_ms` (150), `state_dir` (temp dir), `origin_frames` (3), `discover`, `logger` (callable, or `false`), and `client_name`, `client_version`, `framework`, `framework_version` for framework packages.

Off by `configure()` or the environment (`Config::off()`), `gaze()` and `ray()` return `Noop`, which depends on nothing: a process loads `Config` and `Noop` and no other class of the package. Every call returns the Noop and does nothing; `gaze_dd()`, `rd()` and `die()` return instead of exiting. It still runs the caller's own code: `pass()`, `group($name, $fn)`, `measure($fn)`, and the methods a framework declares with `Noop::runs()` (gaze-laravel's `showQueries($fn)`, `countQueries($fn)`...).

Paused with `disable()`, `gaze()` returns one shared inert `Gaze` instance before any value is touched: no cloning, no closures called, no network, `die()` returns, and `enable()` resumes.

## Values

Values are converted at call time with Symfony's `VarCloner` (so casters decide what objects expose) and a `DumperInterface` walker into RFC-0001 §5 `Value` nodes: `null`, `bool`, `int` (a string beyond 2^53−1), `float` (`"NaN"`, `"INF"`, `"-INF"`, `"-0"` as strings), `str` (`enc: "b64"` for binary, `cut` + `len` when truncated), `list`, `map`, `obj` (props with `pub` / `prot` / `priv` + `decl` / `dyn` / `meta`), `ref` (repeated or recursive objects), `res`, `fn` (closures), `date` and `const` (enums). Scalars and arrays of scalars skip the cloner.

Limits per value: depth 8, 200 items per collection, 5,000 nodes, 16 KiB per string. The app's advertised `limits` can lower them.

Every entry carries `origin`: `file`, `line`, `function`, `class` of the first frame outside this package, plus up to `origin_frames` non-vendor "called from" frames. Framework packages register their own directories with `TheWonderJoy\Gaze\Support\Origin::addInternalPath()`.

## Why ray() is never defined

Composer loads equal-weight `files` autoloads alphabetically, so defining `ray()` here could shadow spatie's. `Gaze::registerRayHelper()` defines it at runtime, only when no `ray()` exists.

## Tests

`vendor/bin/phpunit` starts a fake app with `php -S 127.0.0.1:23610`. Batches produced by the tests are validated against `tests/schema/batch.schema.json`, a copy of the monorepo's `protocol/schema/batch.schema.json`; a test fails when the copy is stale.

## Measured

Windows 11, PHP 8.4.15, libcurl 8.12.1, against a local keep-alive sink that answers in microseconds (the worst case for coalescing; a slower app coalesces more and costs less per call):

| µs per call | default (linger 2 ms) | `linger_ms => 0` |
|---|---|---|
| `gaze('string')` | 47–52 | 35–40 |
| `gaze(['id' => …, 'tags' => […]])` | 57 | 47 |
| `gaze($object)` (4 properties) | 85 | 81 |
| `gaze('x')->red()->label('l')` | 55 | 46 |
| app closed (after the shared down-cache was written) | 1–2 | |
| disabled | 0.4–0.5 | |

Most of an app-up call is libcurl starting the request (~20 µs for the first `curl_multi_exec` on Windows). The client's own work is 7–16 µs for scalars, arrays and chains. spatie/ray costs ~490 µs per call on the same machine.
