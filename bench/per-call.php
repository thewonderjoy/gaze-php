<?php

// Per-call cost of gaze() (client side), against a local keep-alive sink.
// Usage: php bench/per-call.php [N]          (starts bench/sink-server.php on 127.0.0.1:23611)
//        GAZE_BENCH_PORT=23517 php bench/per-call.php   (use an app that is already running)

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use TheWonderJoy\Gaze\Client;
use TheWonderJoy\Gaze\Gaze;

$n = (int) ($argv[1] ?? 3000);
$port = (int) (getenv('GAZE_BENCH_PORT') ?: 23611);
$stateDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gaze-bench-' . getmypid();
@mkdir($stateDir);

$sink = null;
if (!getenv('GAZE_BENCH_PORT')) {
    $sink = proc_open([PHP_BINARY, __DIR__ . '/sink-server.php', (string) $port], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $deadline = microtime(true) + 5;
    while (microtime(true) < $deadline && !($fp = @fsockopen('127.0.0.1', $port, $e, $s, 0.2))) {
        usleep(20000);
    }
    if (empty($fp)) {
        fwrite(STDERR, "sink did not start\n");
        exit(1);
    }
    fclose($fp);
}

$stats = static function () use ($port): array {
    $raw = @file_get_contents("http://127.0.0.1:$port/_stats");

    return $raw === false ? ['requests' => '?', 'entries' => '?', 'conns' => '?'] : json_decode($raw, true);
};
$fresh = static function (array $extra = []) use ($port, $stateDir): void {
    Gaze::reset();
    Gaze::configure(['host' => '127.0.0.1', 'port' => $port, 'state_dir' => $stateDir, 'logger' => false] + $extra);
};
$median = static function (callable $run): float {
    $r = [$run(), $run(), $run()];
    sort($r);

    return $r[1];
};

class BenchUser
{
    public int $id = 7;
    public string $email = 'ada@example.com';
    protected array $roles = ['admin'];
    private ?\DateTimeImmutable $since = null;
}

$cases = [
    'gaze("string")' => static function (int $i): void {
        gaze('hello world');
    },
    'gaze($i)' => static function (int $i): void {
        gaze($i);
    },
    'gaze(small array)' => static function (int $i): void {
        gaze(['id' => $i, 'name' => 'Ada', 'tags' => ['a', 'b'], 'active' => true]);
    },
    'gaze(object)' => static function (int $i): void {
        static $user = null;
        $user ??= new BenchUser();
        gaze($user);
    },
    'gaze("x")->red()->label()' => static function (int $i): void {
        gaze('chained')->red()->label('label');
    },
];

printf("PHP %s, libcurl %s, %s, N=%d, port %d (%s)\n\n", PHP_VERSION, curl_version()['version'], PHP_OS_FAMILY, $n, $port, $sink ? 'bench sink, keep-alive' : 'existing app');
printf("%-30s %10s %12s %13s %20s\n", 'µs per call (app up)', 'tight', '+0.2ms work', 'linger off', 'req/entries on|off');

$work = static function (float $ms): void {
    $end = hrtime(true) + (int) ($ms * 1e6);
    while (hrtime(true) < $end) {
    }
};

$tightLoop = static function (array $config, callable $case) use ($fresh, $n): float {
    $fresh($config);
    gaze('warm-up'); // handshake + class loading, not part of the per-call cost
    Gaze::client()->flush();
    $t = hrtime(true);
    for ($i = 0; $i < $n; ++$i) {
        $case($i);
    }
    $us = (hrtime(true) - $t) / 1e3 / $n;
    Gaze::client()->flush();

    return $us;
};

foreach ($cases as $name => $case) {
    // linger off: pure async-immediate (note 12 option C); tight loops coalesce, but a tail entry
    // queued behind a request in flight waits for the next call
    $noLinger = $median(fn () => $tightLoop(['linger_ms' => 0], $case));
    $s0 = $stats();
    // default (linger 2 ms): the tail is on the wire before the call returns
    $tight = $median(fn () => $tightLoop([], $case));
    $s = $stats();
    // with app work between calls: closer to a real request (most calls start their own request)
    $m = (int) max(200, $n / 5);
    $withWork = $median(function () use ($fresh, $case, $m, $work): float {
        $fresh();
        gaze('warm-up');
        Gaze::client()->flush();
        $t = hrtime(true);
        for ($i = 0; $i < $m; ++$i) {
            $case($i);
            $work(0.2);
        }
        $us = (hrtime(true) - $t) / 1e3 / $m - 200;
        Gaze::client()->flush();

        return $us;
    });
    $stats();
    printf("%-30s %10.1f %12.1f %13.1f %20s\n", $name, $tight, $withWork, $noLinger, $s['requests'] . '/' . $s['entries'] . ' | ' . $s0['requests'] . '/' . $s0['entries']);
}

// app down (after the shared down-cache was written) and disabled
$closed = 23618;
$down = $median(function () use ($fresh, $closed, $n, $stateDir): float {
    Gaze::reset();
    Gaze::configure(['host' => '127.0.0.1', 'port' => $closed, 'state_dir' => $stateDir, 'logger' => false]);
    gaze('probe');
    Gaze::client()->flush();
    $t = hrtime(true);
    for ($i = 0; $i < $n; ++$i) {
        gaze(['id' => $i])->red();
    }

    return (hrtime(true) - $t) / 1e3 / $n;
});
Client::reset();
$t = hrtime(true);
gaze('first call of a new process, app down, cache warm');
$downFirst = (hrtime(true) - $t) / 1e3;

$disabled = $median(function () use ($fresh, $n): float {
    $fresh(['enabled' => false]);
    $t = hrtime(true);
    for ($i = 0; $i < $n; ++$i) {
        gaze(['id' => $i])->red();
    }

    return (hrtime(true) - $t) / 1e3 / $n;
});

printf("\n%-34s %12.2f\n", 'app down (cached), per call µs', $down);
printf("%-34s %12.1f\n", 'app down, first call new process µs', $downFirst);
printf("%-34s %12.2f\n", 'disabled, per call µs', $disabled);
printf("\nreq/entries: tight loops, 3 runs each, default (linger 2 ms) | linger off. All entries arrive either way.\n");
printf("tight: calls back to back; +0.2ms work: busy work between calls (subtracted).\n");

Gaze::reset();
if ($sink !== null) {
    proc_terminate($sink);
    proc_close($sink);
}
foreach (glob($stateDir . '/*') ?: [] as $f) {
    @unlink($f);
}
@rmdir($stateDir);
