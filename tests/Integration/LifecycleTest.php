<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Tests\Integration;

use TheWonderJoy\Gaze\Exceptions\StopExecutionRequested;
use TheWonderJoy\Gaze\Gaze;
use TheWonderJoy\Gaze\Tests\TestCase;

final class LifecycleTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        self::startServer();
    }

    public function testShutdownDrainSendsPendingEntriesAfterExit(): void
    {
        $r = $this->runPhp($this->childConfig() . <<<'PHP'
            gaze('before exit')->green();
            $kept = gaze('held in a variable');
            for ($i = 0; $i < 20; $i++) { gaze("burst $i"); }
            exit(3);
            PHP);
        $this->assertSame(3, $r['exit'], $r['err']);
        $texts = array_map(fn ($e) => $e['body']['values'][0]['v'], $this->entries());
        $this->assertContains('before exit', $texts);
        $this->assertContains('held in a variable', $texts);
        $this->assertContains('burst 19', $texts);
        $this->assertCount(22, $texts);
        $this->assertBatchesValid();
    }

    public function testShutdownDrainRunsAfterAFatalError(): void
    {
        $r = $this->runPhp($this->childConfig() . <<<'PHP'
            gaze('before fatal');
            undefined_function_for_gaze_test();
            PHP);
        $this->assertSame(255, $r['exit']);
        $this->assertSame('before fatal', $this->entries()[0]['body']['values'][0]['v'] ?? null);
    }

    public function testEntriesFromLaterShutdownFunctionsAreDrainedToo(): void
    {
        $r = $this->runPhp($this->childConfig() . <<<'PHP'
            gaze('first');
            register_shutdown_function(function () { gaze('from a later shutdown function'); });
            PHP);
        $this->assertSame(0, $r['exit'], $r['err']);
        $texts = array_map(fn ($e) => $e['body']['values'][0]['v'], $this->entries());
        $this->assertSame(['first', 'from a later shutdown function'], $texts);
    }

    public function testGazeDdFlushesSynchronouslyThenExits(): void
    {
        $r = $this->runPhp($this->childConfig(self::PORT, ['drain_ms' => 0]) . <<<'PHP'
            gaze_dd('last words', ['n' => 1]);
            echo 'not reached';
            PHP);
        $this->assertSame(1, $r['exit']);
        $this->assertSame('', $r['out']);
        $entries = $this->entries();
        $this->assertCount(1, $entries);
        $this->assertSame('last words', $entries[0]['body']['values'][0]['v']);
        $this->assertBatchesValid();
    }

    public function testPauseLongPollsUntilReleased(): void
    {
        $this->control(['lock_script' => ['active', 'active', 'released'], 'lock_wait_ms' => 150]);
        gaze('before pause');
        $t = microtime(true);
        gaze()->pause();
        $ms = (microtime(true) - $t) * 1000;

        $requests = $this->requests();
        $lockEntry = null;
        $lockBatchIndex = null;
        foreach ($requests as $i => $r) {
            if ($r['path'] === '/v1/batch') {
                foreach (json_decode($r['body'], true)['entries'] as $e) {
                    if ($e['kind'] === 'lock') {
                        $lockEntry = $e;
                        $lockBatchIndex = $i;
                    }
                }
            }
        }
        $this->assertNotNull($lockEntry);
        $this->assertSame($lockEntry['id'], $lockEntry['body']['name']);
        $this->assertSame(__FILE__, $lockEntry['origin']['file']);
        $polls = array_values(array_filter($requests, fn ($r) => str_starts_with($r['path'], '/v1/locks/')));
        $this->assertCount(3, $polls);
        foreach ($polls as $poll) {
            $this->assertSame('/v1/locks/' . $lockEntry['id'] . '?wait=25', $poll['uri']);
        }
        $this->assertLessThan(array_search($polls[0], $requests, true), $lockBatchIndex, 'the lock is delivered before polling starts');
        $this->assertGreaterThanOrEqual(400, $ms);
        $this->assertContains('before pause', array_map(fn ($e) => $e['body']['values'][0]['v'] ?? null, $this->entries()));
        $this->assertBatchesValid();
    }

    public function testStopExecutionThrows(): void
    {
        $this->control(['lock_script' => ['stopped']]);
        $this->expectException(StopExecutionRequested::class);
        gaze()->pause();
    }

    public function testPauseResumesWhenTheLockIsUnknown(): void
    {
        $this->control(['lock_script' => ['404']]);
        gaze()->pause();
        $this->assertCount(1, array_filter($this->requests(), fn ($r) => str_starts_with($r['path'], '/v1/locks/')));
    }

    public function testPauseReturnsAtOnceWhenTheAppIsDown(): void
    {
        Gaze::configure($this->baseConfig(self::CLOSED_PORT));
        $t = microtime(true);
        gaze()->pause();
        gaze()->pause();
        $this->assertLessThan(600, (microtime(true) - $t) * 1000);
    }

    public function testFlushOnAnUnusedClientIsFree(): void
    {
        $t = microtime(true);
        $this->assertTrue(Gaze::client()->flush());
        gaze()->flush();
        $this->assertLessThan(5, (microtime(true) - $t) * 1000);
        $this->assertSame([], $this->requests());
    }
}
