<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Tests\Integration;

use TheWonderJoy\Gaze\Client;
use TheWonderJoy\Gaze\Gaze;
use TheWonderJoy\Gaze\Support\StateFile;
use TheWonderJoy\Gaze\Tests\TestCase;
use TheWonderJoy\Gaze\Transport\HostResolver;

final class TransportTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        self::startServer();
    }

    public function testACallDoesNotWaitForTheAppToAnswer(): void
    {
        gaze('warm up'); // handshake happens synchronously on the first call of a process
        Gaze::client()->flush();
        $this->control(['batch_delay_ms' => 400]);

        $t = microtime(true);
        gaze('slow app');
        $callMs = (microtime(true) - $t) * 1000;
        $this->assertLessThan(50, $callMs, 'async-immediate: the call only starts the request');

        $t = microtime(true);
        $this->assertTrue(Gaze::client()->flush());
        $this->assertGreaterThan(200, (microtime(true) - $t) * 1000, 'flush() waits for the answer');
        $this->assertCount(2, $this->entries());
    }

    public function testEntriesCoalesceWhileARequestIsInFlight(): void
    {
        gaze('warm up');
        Gaze::client()->flush();
        $this->control(['batch_delay_ms' => 150]);

        for ($i = 0; $i < 30; ++$i) {
            gaze($i);
        }
        Gaze::client()->flush();

        $batches = $this->batches();
        $this->assertLessThanOrEqual(4, count($batches), 'at most one request in flight; the rest coalesce');
        $entries = $this->entries();
        $this->assertCount(31, $entries);
        $this->assertSame(range(0, 30), array_column($entries, 'seq'), 'order is kept');
        $this->assertBatchesValid();
    }

    public function testLingerSendsTheTailBeforeUserCodeBlocks(): void
    {
        gaze('warm up');
        Gaze::client()->flush();
        $this->control(['batch_delay_ms' => 100]);
        $arrived = function (): array {
            return array_map(fn ($e) => $e['body']['values'][0]['v'], $this->entries());
        };

        // linger off: 'b' waits behind the request in flight until the next call or flush
        Gaze::configure(['linger_ms' => 0]);
        gaze('a0');
        gaze('b0');
        usleep(350000); // user code blocks; no gaze call happens
        $this->assertNotContains('b0', $arrived());
        Gaze::client()->flush();
        $this->assertContains('b0', $arrived());

        // linger on: the second call waits (bounded) for 'a' and puts 'b' on the wire before returning
        Gaze::configure(['linger_ms' => 200]);
        gaze('a1');
        gaze('b1');
        usleep(250000);
        $this->assertContains('b1', $arrived());
        Gaze::client()->flush();
    }

    public function testHandshakeIsSharedByLaterProcesses(): void
    {
        gaze('first process');
        Gaze::client()->flush();
        Client::reset(); // a new request under FPM: statics are gone, the temp file is not
        gaze('second process');
        Gaze::client()->flush();
        $result = $this->runPhp($this->childConfig() . 'gaze("third process");');
        $this->assertSame(0, $result['exit'], $result['err']);

        $hellos = array_filter($this->requests(), fn ($r) => $r['path'] === '/v1/hello');
        $this->assertCount(1, $hellos, 'one GET /v1/hello for all three');
        $this->assertCount(3, $this->entries());
        $state = StateFile::read(StateFile::endpointKey('127.0.0.1', self::PORT));
        $this->assertGreaterThan(time(), $state['up_until']);
        $this->assertSame('instance-1', $state['instance']);
    }

    public function testAChangedInstanceIsRecorded(): void
    {
        StateFile::write(StateFile::endpointKey('127.0.0.1', self::PORT), ['up_until' => time() - 1, 'instance' => 'old-instance']);
        gaze('x');
        Gaze::client()->flush();
        $state = StateFile::read(StateFile::endpointKey('127.0.0.1', self::PORT));
        $this->assertTrue($state['restarted']);
        $this->assertSame('instance-1', Gaze::client()->status()['hello']['server']['instance']);
    }

    public function testAppDownCostsOneConnectTimeoutThenUsesTheSharedDownCache(): void
    {
        $free = @stream_socket_server('tcp://127.0.0.1:' . self::CLOSED_PORT);
        $this->assertNotFalse($free, 'port ' . self::CLOSED_PORT . ' must be closed for this test');
        fclose($free);
        Gaze::configure($this->baseConfig(self::CLOSED_PORT));

        $t = microtime(true);
        gaze('nobody listens')->red();
        Gaze::client()->flush();
        $first = (microtime(true) - $t) * 1000;
        $this->assertLessThan(600, $first, 'bounded by the 100 ms connect timeout, never a 2 s stall');
        $this->assertSame('down', Gaze::client()->status()['state']);
        $state = StateFile::read(StateFile::endpointKey('127.0.0.1', self::CLOSED_PORT));
        $this->assertGreaterThan(time(), $state['down_until']);

        // same process: no network at all
        $t = microtime(true);
        for ($i = 0; $i < 100; ++$i) {
            gaze('still down', ['x' => $i])->blue();
        }
        $this->assertLessThan(20, (microtime(true) - $t) * 1000);

        // a new process (FPM request, CLI run) reads the shared cache instead of connecting
        Client::reset();
        $t = microtime(true);
        gaze('new process');
        Gaze::client()->flush();
        $this->assertLessThan(20, (microtime(true) - $t) * 1000);

        $child = $this->runPhp($this->childConfig(self::CLOSED_PORT) . '$t = hrtime(true); gaze("child"); \TheWonderJoy\Gaze\Gaze::client()->flush(); echo round((hrtime(true) - $t) / 1e6, 2);');
        $this->assertLessThan(20, (float) $child['out'], 'child process: ' . $child['out'] . $child['err']);
        fwrite(STDERR, sprintf("\n[app down] first call %.1f ms; with down-cache: child process %s ms\n", $first, $child['out']));
    }

    public function testServerErrorMarksTheAppDown(): void
    {
        $this->control(['batch_status' => 500]);
        gaze('a');
        Gaze::client()->flush();
        $this->assertSame('down', Gaze::client()->status()['state']);
        gaze('b');
        Gaze::client()->flush();
        $this->assertCount(1, $this->batches(), 'no further requests while down');
        $this->assertCount(1, $this->logs);
        $this->assertStringContainsString('HTTP 500', $this->logs[0]);
    }

    public function testATimeoutIsRetriedInsteadOfMarkingTheAppDown(): void
    {
        // Reproduces a blocked process: the reply arrives after the client's timeout.
        Gaze::configure(['timeout_ms' => 300] + $this->baseConfig());
        $this->control(['batch_delay_ms' => 600]);
        gaze('sent while blocked');
        Gaze::client()->flush(500);
        $this->assertSame('up', Gaze::client()->status()['state'], 'one timeout does not mark the app down');
        $this->waitForFakeAppIdle();
        gaze('after');
        Gaze::client()->flush();
        $this->waitForFakeAppIdle();
        $values = array_map(fn ($e) => $e['body']['values'][0]['v'], $this->entries());
        $this->assertContains('sent while blocked', $values, 'the timed-out batch is resent (ids make it idempotent)');
        $this->assertContains('after', $values);
        $this->assertSame([], $this->logs);
    }

    public function testRepeatedTimeoutsStillMarkTheAppDown(): void
    {
        Gaze::configure(['timeout_ms' => 200] + $this->baseConfig());
        $this->control(['batch_delay_ms' => 500]);
        gaze('never acknowledged');
        Gaze::client()->flush(2000);
        $this->assertSame('down', Gaze::client()->status()['state']);
        $this->waitForFakeAppIdle();
    }

    /**
     * The fake app (php -S) handles one request at a time: wait until the delayed batches it is
     * still answering are done, so they are not recorded during the next test.
     */
    private function waitForFakeAppIdle(): void
    {
        $this->control([]);
        @file_get_contents('http://127.0.0.1:' . self::PORT . '/v1/hello', false, stream_context_create(['http' => ['timeout' => 5]]));
    }

    public function testClientErrorDropsTheBatchAndLogsOnce(): void
    {
        $this->control(['batch_status' => 400]);
        gaze('a');
        Gaze::client()->flush();
        gaze('b');
        Gaze::client()->flush();
        $this->assertCount(2, $this->batches(), 'a 4xx does not mark the app down');
        $this->assertSame('up', Gaze::client()->status()['state']);
        $this->assertCount(1, $this->logs, 'logged once');
        $this->assertStringContainsString('HTTP 400', $this->logs[0]);
    }

    public function testTooManyRequestsIsRetried(): void
    {
        $this->control(['batch_status' => 429]);
        gaze('throttled');
        Gaze::client()->flush(300);
        $this->control([]);
        Gaze::client()->flush();
        $bodies = array_map(fn ($b) => $b['entries'][0]['body']['values'][0]['v'], $this->batches());
        $this->assertGreaterThanOrEqual(2, count($bodies));
        $this->assertSame('throttled', end($bodies), 'the batch is sent again after 429');
    }

    public function testSomethingElseOnThePortIsTreatedAsDown(): void
    {
        $this->control(['hello_status' => 404]); // e.g. the Ray app or another server
        gaze('a');
        Gaze::client()->flush();
        $this->assertSame([], $this->batches());
        $this->assertSame('down', Gaze::client()->status()['state']);
        $this->assertStringContainsString('not a Gaze app', $this->logs[0] ?? '');
    }

    public function testDisabledSendsNothingAndConvertsNothing(): void
    {
        Gaze::configure(['enabled' => false]);
        $touched = false;
        $spy = new class ($touched) {
            public function __construct(private bool &$touched)
            {
            }

            public function __debugInfo(): array
            {
                $this->touched = true;

                return [];
            }
        };
        $called = false;
        $a = gaze($spy, function () use (&$called) {
            $called = true;
        })->red()->label('x');
        $b = gaze();
        gaze()->table(['a' => $spy])->count();
        gaze()->measure('t');
        gaze()->pause();
        gaze()->newScreen();
        Gaze::client()->flush();

        $this->assertSame($a, $b, 'one shared inert instance, no allocation per call');
        $this->assertFalse($touched, 'values are not cloned');
        $this->assertFalse($called, 'closures are not called');
        $this->assertSame([], $this->requests(), 'not even a handshake');
        $this->assertSame(7, gaze()->pass(7));

        $child = $this->runPhp('gaze("env disabled")->red(); \TheWonderJoy\Gaze\Gaze::client()->flush(); echo gaze()->enabled() ? "on" : "off";', [
            'GAZE_ENABLED' => 'false', 'GAZE_HOST' => '127.0.0.1', 'GAZE_PORT' => (string) self::PORT,
        ]);
        $this->assertSame('off', $child['out'], $child['err']);
        $this->assertSame([], $this->requests());
    }

    public function testOffLoadsOnlyTheNoopAndNeverExits(): void
    {
        $code = <<<'PHP'
            use TheWonderJoy\Gaze\Gaze;
            $ran = 0;
            gaze('x', ['y' => 1])->red()->label('l')->table(['a' => 1])->count();
            gaze()->enable()->send('still off after enable()');
            gaze()->die();
            gaze_dd('gaze_dd returns');
            gaze()->pause();
            gaze()->flush();
            $group = gaze()->group('g', fn ($g) => 42);
            gaze()->measure(function () use (&$ran) { $ran++; });
            $classes = array_values(array_filter(get_declared_classes(), static fn ($c) => str_starts_with($c, 'TheWonderJoy\\')));
            echo json_encode(['classes' => $classes, 'class' => get_class(gaze()), 'group' => $group, 'ran' => $ran, 'pass' => gaze()->pass(7), 'enabled' => gaze()->enabled()]);
            PHP;
        $quiet = ['classes' => ['TheWonderJoy\Gaze\Config', 'TheWonderJoy\Gaze\Noop'], 'class' => 'TheWonderJoy\Gaze\Noop', 'group' => 42, 'ran' => 1, 'pass' => 7, 'enabled' => false];
        foreach ([['GAZE_ENABLED' => 'false'], ['APP_ENV' => 'production'], ['APP_ENV' => 'prod']] as $env) {
            $r = $this->runPhp($code, $env + ['GAZE_HOST' => '127.0.0.1', 'GAZE_PORT' => (string) self::PORT]);
            $this->assertSame(0, $r['exit'], $r['out'] . $r['err']);
            $this->assertSame($quiet, json_decode($r['out'], true), $r['err']);
        }
        $this->assertSame([], $this->requests(), 'not even a handshake');

        // configure() switches it off for good; disable() only pauses, and die() returns while paused.
        Gaze::configure(['enabled' => false]);
        gaze()->enable();
        $this->assertInstanceOf(\TheWonderJoy\Gaze\Noop::class, gaze());
        Gaze::configure(['enabled' => true]);
        $paused = gaze()->disable();
        $this->assertSame($paused, $paused->die(), 'die() returns while paused');
        $this->assertTrue(gaze()->enable()->enabled(), 'enable() resumes after disable()');
    }

    public function testEnvironmentSelectsHostAndPortWithRayFallback(): void
    {
        $child = $this->runPhp('$c = \TheWonderJoy\Gaze\Gaze::configure(["state_dir" => ' . var_export($this->stateDir, true) . ']); gaze("via RAY_HOST/RAY_PORT"); \TheWonderJoy\Gaze\Gaze::client()->flush();', [
            'RAY_HOST' => '127.0.0.1', 'RAY_PORT' => (string) self::PORT,
        ]);
        $this->assertSame(0, $child['exit'], $child['err']);
        $this->assertSame('via RAY_HOST/RAY_PORT', $this->entries()[0]['body']['values'][0]['v']);
    }

    public function testDiscoveryProbesCandidatesInParallel(): void
    {
        $t = microtime(true);
        // 192.0.2.1 is TEST-NET-1 (never routed): it must cost one probe timeout, not one per candidate
        $winner = HostResolver::probe(['192.0.2.1', 'localhost', '127.0.0.1'], self::PORT, 150);
        $ms = (microtime(true) - $t) * 1000;
        $this->assertSame('localhost', $winner, 'first candidate in priority order that answers wins');
        $this->assertLessThan(700, $ms);
        $this->assertCount(2, array_filter($this->requests(), fn ($r) => $r['path'] === '/v1/hello'));
        $this->assertNull(HostResolver::probe(['127.0.0.1'], self::CLOSED_PORT, 100));
    }

    public function testWithoutAConfiguredHostTheDefaultIsLoopback(): void
    {
        Gaze::reset();
        Gaze::configure(['port' => self::PORT, 'state_dir' => $this->stateDir]);
        $this->assertNull(Gaze::config()['host']);
        $this->assertSame(HostResolver::DEFAULT_HOST, HostResolver::resolve(self::PORT));
        gaze('discovered');
        Gaze::client()->flush();
        $this->assertSame('127.0.0.1', Gaze::client()->status()['host']);
        $this->assertCount(1, $this->entries());
    }

    public function testBatchesAreValidJsonEvenWithInvalidUtf8(): void
    {
        gaze()->text("broken \xC3\x28 utf-8");
        gaze()->label("bad \xff label")->send('x');
        Gaze::client()->flush();
        $this->assertCount(2, $this->entries());
        $this->assertBatchesValid();
    }
}
