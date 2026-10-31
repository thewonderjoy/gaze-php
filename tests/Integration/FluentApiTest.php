<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Tests\Integration;

use TheWonderJoy\Gaze\Gaze;
use TheWonderJoy\Gaze\Tests\Fixtures\Suit;
use TheWonderJoy\Gaze\Tests\Fixtures\User;
use TheWonderJoy\Gaze\Tests\TestCase;

final class FluentApiTest extends TestCase
{
    private const UUID7 = '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    public static function setUpBeforeClass(): void
    {
        self::startServer();
    }

    public function testChainedModifiersProduceOneRequestWithOneAdd(): void
    {
        gaze('hello')->red()->label('greeting')->large()->hide()->expand(2, 'a.b');
        Gaze::client()->flush();

        $batches = $this->batches();
        $this->assertCount(1, $batches, 'one POST for the whole chain');
        $this->assertCount(1, $batches[0]['entries']);
        $e = $batches[0]['entries'][0];
        $this->assertSame('add', $e['op']);
        $this->assertSame('log', $e['kind']);
        $this->assertSame([['k' => 'str', 'v' => 'hello']], $e['body']['values']);
        $this->assertSame(['red', 'greeting', 'lg', true, ['level' => 2, 'paths' => ['a.b']]], [$e['color'], $e['label'], $e['size'], $e['hidden'], $e['expand']]);
        $this->assertBatchesValid();
    }

    public function testModifiersOnAnEntryThatWasAlreadySentBecomeAPatch(): void
    {
        $g = gaze('kept');
        Gaze::client()->flush();
        $g->green()->small();
        Gaze::client()->flush();

        // Patches are not held (the instance lives in a variable, so its chain end is unknown):
        // green() leaves at once; small() folds into it only if it is still queued.
        $entries = $this->entries();
        $this->assertSame('add', $entries[0]['op']);
        $patches = array_slice($entries, 1);
        $this->assertNotEmpty($patches);
        $merged = [];
        foreach ($patches as $patch) {
            $this->assertSame('patch', $patch['op']);
            $this->assertSame($entries[0]['id'], $patch['id']);
            $this->assertArrayNotHasKey('body', $patch);
            $this->assertArrayNotHasKey('kind', $patch);
            $merged = $patch + $merged;
        }
        $this->assertSame(['green', 'sm'], [$merged['color'], $merged['size']]);
        $this->assertBatchesValid();
    }

    public function testSendingAgainOnAKeptInstanceUpdatesTheSameEntry(): void
    {
        $ray = gaze('counting down');
        foreach ([3, 2, 1] as $n) {
            $ray->send($n);
        }
        Gaze::client()->flush();

        $entries = $this->entries();
        $this->assertSame(['add', 'update', 'update', 'update'], array_column($entries, 'op'));
        $this->assertCount(1, array_unique(array_column($entries, 'id')));
        $this->assertSame(['k' => 'int', 'v' => 1], $entries[3]['body']['values'][0]);
        $this->assertBatchesValid();
    }

    public function testModifiersBeforeContentAreAppliedToTheAdd(): void
    {
        $g = gaze();
        $g->purple()->label('before');
        $g->send('value');
        unset($g);
        Gaze::client()->flush();

        $entries = $this->entries();
        $this->assertCount(1, $entries);
        $this->assertSame(['add', 'purple', 'before'], [$entries[0]['op'], $entries[0]['color'], $entries[0]['label']]);
    }

    public function testCountUpdatesOneStoredEntry(): void
    {
        for ($i = 0; $i < 3; ++$i) {
            gaze()->count('loop');
        }
        for ($i = 0; $i < 2; ++$i) {
            gaze()->count();
        }
        Gaze::client()->flush();

        $entries = $this->entries();
        $this->assertSame(['add', 'update', 'update', 'add', 'update'], array_column($entries, 'op'));
        $this->assertSame($entries[0]['id'], $entries[2]['id']);
        $this->assertNotSame($entries[0]['id'], $entries[3]['id']);
        $this->assertSame('Called `loop` 1 time.', $entries[0]['body']['text']);
        $this->assertSame('Called `loop` 3 times.', $entries[2]['body']['text']);
        $this->assertSame('Called 2 times.', $entries[4]['body']['text']);
        $this->assertSame(3, gaze()->counterValue('loop'));
        gaze()->clearCounters();
        $this->assertSame(0, gaze()->counterValue('loop'));
        $this->assertBatchesValid();
    }

    public function testMeasure(): void
    {
        gaze()->measure('db');
        usleep(15000);
        gaze()->measure('db');
        usleep(5000);
        gaze()->measure('db');
        gaze()->measure(fn () => usleep(10000));
        gaze()->stopTime('db');
        gaze()->measure('db');
        Gaze::client()->flush();

        $bodies = array_column($this->entries(), 'body');
        $this->assertSame(['db', 'db', 'db', 'closure', 'db'], array_column($bodies, 'name'));
        $this->assertSame([true, false, false, true, true], array_column($bodies, 'is_new'));
        $this->assertGreaterThanOrEqual(14, $bodies[1]['total_ms']);
        $this->assertGreaterThanOrEqual(19, $bodies[2]['total_ms']);
        $this->assertLessThan($bodies[2]['total_ms'], $bodies[2]['since_last_ms']);
        $this->assertGreaterThanOrEqual(9, $bodies[3]['total_ms']);
        $this->assertSame(0, $bodies[4]['total_ms']);
        $this->assertBatchesValid();
    }

    public function testExceptionWithFramesPreviousAndContext(): void
    {
        $line = 0;
        try {
            $this->throwing($line);
        } catch (\Throwable $e) {
            gaze()->exception($e, ['order' => 5]);
        }
        Gaze::client()->flush();

        $e = $this->entries()[0];
        $this->assertSame(['exception', 'red'], [$e['kind'], $e['color']]);
        $body = $e['body'];
        $this->assertSame(\RuntimeException::class, $body['class']);
        $this->assertSame('payment failed', $body['message']);
        $this->assertSame(42, $body['code']);
        $this->assertSame(__FILE__, $body['frames'][0]['file']);
        $this->assertSame($line, $body['frames'][0]['line']);
        $this->assertSame('throwing', $body['frames'][0]['function']);
        $this->assertSame(self::class, $body['frames'][0]['class']);
        $this->assertFalse($body['frames'][0]['vendor']);
        $this->assertContains($line, array_column($body['frames'][0]['snippet'], 'line'));
        $this->assertSame(__FUNCTION__, $body['frames'][1]['function'], 'second frame is the caller');
        $this->assertTrue($body['frames'][count($body['frames']) - 1]['vendor'] ?? false, 'PHPUnit frames are vendor frames');
        $this->assertSame(\LogicException::class, $body['previous']['class']);
        $this->assertSame('map', $body['context']['k']);
        $this->assertBatchesValid();
    }

    public function testEnvelopeIdsSeqTsAndOrigin(): void
    {
        gaze('a'); $line = __LINE__;
        gaze('b');
        gaze()->text('c');
        Gaze::client()->flush();

        $batches = $this->batches();
        $b = $batches[0];
        $this->assertSame(1, $b['v']);
        $this->assertSame('thewonderjoy/gaze-php', $b['client']['name']);
        $this->assertSame(Gaze::VERSION, $b['client']['version']);
        $this->assertSame(['php', PHP_VERSION, getmypid()], [$b['client']['runtime'], $b['client']['runtime_version'], $b['client']['pid']]);
        $this->assertSame(gethostname(), $b['client']['host']);
        $this->assertSame(realpath(dirname(__DIR__, 2)), $b['client']['project']['root']);
        $this->assertMatchesRegularExpression(self::UUID7, $b['session']);

        $entries = $this->entries();
        $this->assertCount(3, $entries);
        $this->assertSame([0, 1, 2], array_column($entries, 'seq'));
        foreach ($entries as $e) {
            $this->assertMatchesRegularExpression(self::UUID7, $e['id']);
            $this->assertEqualsWithDelta(microtime(true) * 1000, $e['ts'], 10000);
        }
        $this->assertSame(['file' => __FILE__, 'line' => $line, 'function' => __FUNCTION__, 'class' => self::class], array_intersect_key($entries[0]['origin'], array_flip(['file', 'line', 'function', 'class'])));
        foreach ($batches as $batch) {
            $this->assertSame($b['session'], $batch['session'], 'one session per process');
        }
        $this->assertBatchesValid();
    }

    public function testValueTreesOnTheWire(): void
    {
        gaze(new User(), Suit::Spades, PHP_INT_MAX, NAN, -0.0, ['list' => [1, 2], 'map' => ['x' => null]], "\xff\x00");
        Gaze::client()->flush();

        $values = $this->entries()[0]['body']['values'];
        $this->assertSame(['obj', 'const', 'int', 'float', 'float', 'map', 'str'], array_column($values, 'k'));
        $self = array_column($values[0]['props'], null, 'n')['self']['val'];
        $this->assertSame(['k' => 'ref', 'to' => $values[0]['id'], 'cls' => User::class], $self);
        $this->assertSame(Suit::class . '::Spades', $values[1]['v']);
        $this->assertSame('9223372036854775807', $values[2]['v']);
        $this->assertSame(['NaN', '-0'], [$values[3]['v'], $values[4]['v']]);
        $this->assertSame('b64', $values[6]['enc']);
        $this->assertBatchesValid();
    }

    public function testCommandsUseTheirOwnIds(): void
    {
        $g = gaze('content');
        $g->newScreen('Checkout')->clearAll()->screenColor('blue')->notify('done')->confetti()->showApp()->hideApp();
        $g->newScreen('__pest_evaluable_it_works');
        Gaze::client()->flush();

        $entries = $this->entries();
        $cmds = array_values(array_filter($entries, fn ($e) => $e['kind'] === 'cmd'));
        $this->assertSame(['new_screen', 'clear', 'screen_color', 'notify', 'confetti', 'show_app', 'hide_app', 'new_screen'], array_map(fn ($e) => $e['body']['cmd'], $cmds));
        $this->assertSame('Checkout', $cmds[0]['body']['name']);
        $this->assertSame('it works', $cmds[7]['body']['name']);
        $this->assertSame('blue', $cmds[2]['body']['color']);
        $this->assertSame('done', $cmds[3]['body']['text']);
        $this->assertCount(9, array_unique(array_column($entries, 'id')), 'commands never reuse the content id');
        $this->assertBatchesValid();
    }

    public function testEveryContentKind(): void
    {
        $tmp = $this->stateDir . DIRECTORY_SEPARATOR . 'note.txt';
        file_put_contents($tmp, "line 1\r\nline 2\n");
        $png = $this->stateDir . DIRECTORY_SEPARATOR . 'dot.png';
        file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));

        gaze()->text('<b>not html</b>');
        gaze()->html('<b>html</b>');
        gaze()->markdown('# Title');
        gaze()->mermaid('graph TD; A-->B');
        gaze()->json('{"a":[1,2],"o":{}}');
        gaze()->toJson(['x' => 1]);
        gaze()->xml('<root><child a="1"/></root>');
        gaze()->table(['name' => 'Ada', 'age' => 36], 'Person');
        gaze()->table([['id' => 1, 'name' => 'a'], ['id' => 2, 'name' => 'b', 'extra' => true]]);
        gaze()->image($png);
        gaze()->image('https://example.com/x.png');
        gaze()->file($tmp);
        gaze()->file($this->stateDir . '/missing.txt');
        gaze()->url('example.com/docs', 'Docs');
        gaze()->link('https://example.com/?a=1&b="2"');
        gaze()->carbon(new \DateTimeImmutable('2026-10-31 12:00:00', new \DateTimeZone('UTC')));
        gaze()->date(null);
        gaze()->className($this);
        gaze()->separator();
        gaze()->phpinfo();
        gaze()->phpinfo('memory_limit', 'no_such_setting');
        gaze()->trace();
        gaze()->caller();
        gaze()->raw(fn () => 1);
        gaze()->custom('request', ['method' => 'GET', 'url' => '/x', 'status' => 200]);
        gaze()->custom('Not Valid!', []);
        Gaze::client()->flush();

        $entries = $this->entries();
        $kinds = array_column($entries, 'kind');
        $this->assertSame(['text', 'html', 'markdown', 'mermaid', 'json', 'json', 'text', 'table', 'table', 'image', 'image', 'text', 'text',
            'html', 'html', 'date', 'date', 'log', 'separator', 'table', 'table', 'trace', 'trace', 'log', 'request', 'xnot_valid_'], $kinds);
        $b = array_column($entries, 'body');
        $this->assertSame('<b>not html</b>', $b[0]['text']);
        $this->assertSame('map', $b[4]['value']['k']);
        $this->assertSame(['k' => 'map', 'count' => 0, 'entries' => []], $b[4]['value']['entries'][1][1], '{} stays a map');
        $this->assertStringContainsString("<child a=\"1\"/>", $b[6]['text']);
        $this->assertSame('XML', $entries[6]['label']);
        $this->assertSame([['name', ['k' => 'str', 'v' => 'Ada']], ['age', ['k' => 'int', 'v' => 36]]], $b[7]['entries']);
        $this->assertSame('Person', $entries[7]['label']);
        $this->assertSame(['id', 'name', 'extra'], $b[8]['columns']);
        $this->assertSame(['k' => 'undef'], $b[8]['rows'][0][2]);
        $this->assertStringStartsWith('data:image/png;base64,', $b[9]['src']);
        $this->assertSame('https://example.com/x.png', $b[10]['src']);
        $this->assertSame("line 1\r\nline 2\n", $b[11]['text']);
        $this->assertSame('note.txt', $entries[11]['label']);
        $this->assertStringStartsWith('File not found', $b[12]['text']);
        $this->assertSame('<a href="https://example.com/docs">Docs</a>', $b[13]['html']);
        $this->assertSame('<a href="https://example.com/?a=1&amp;b=&quot;2&quot;">https://example.com/?a=1&amp;b=&quot;2&quot;</a>', $b[14]['html']);
        $this->assertSame(['2026-10-31 12:00:00', 1793448000, 'UTC'], [$b[15]['formatted'], $b[15]['timestamp'], $b[15]['timezone']]);
        $this->assertNull($b[16]['formatted']);
        $this->assertSame(self::class, $b[17]['values'][0]['v']);
        $this->assertSame('PHPInfo', $entries[19]['label']);
        $this->assertSame(['memory_limit', 'no_such_setting'], array_column($b[20]['entries'], 0));
        $this->assertSame(['k' => 'const', 'v' => 'not set'], $b[20]['entries'][1][1]);
        $this->assertSame(__FUNCTION__, $b[21]['frames'][0]['function']);
        $this->assertSame(__FILE__, $b[21]['frames'][0]['file']);
        $this->assertSame('Caller', $entries[22]['label']);
        $this->assertSame('fn', $b[23]['values'][0]['k'], 'raw() does not call closures');
        $this->assertBatchesValid();
    }

    public function testSendCallsClosuresLikeRay(): void
    {
        gaze(fn () => 'computed', fn (Gaze $g) => $g);
        $this->assertSame('x', gaze()->pass('x'));
        Gaze::client()->flush();

        $entries = $this->entries();
        $this->assertSame([['k' => 'str', 'v' => 'computed']], $entries[0]['body']['values']);
        $this->assertSame([['k' => 'str', 'v' => 'x']], $entries[1]['body']['values']);
    }

    public function testLimitAndOnce(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            gaze()->limit(2)->send("limited $i");
        }
        for ($i = 0; $i < 3; ++$i) {
            gaze()->once("once $i");
        }
        Gaze::client()->flush();

        $texts = array_map(fn ($e) => $e['body']['values'][0]['v'], $this->entries());
        $this->assertSame(['limited 0', 'limited 1', 'once 0'], $texts);
    }

    public function testRateLimiterSendsOneNoticeThenDrops(): void
    {
        Gaze::rateLimiter()->max(3);
        for ($i = 0; $i < 6; ++$i) {
            gaze("v$i");
        }
        Gaze::client()->flush();
        $entries = $this->entries();
        $this->assertCount(4, $entries);
        $this->assertSame('Rate limit has been reached...', $entries[3]['body']['text']);
        $this->assertSame('Rate limit', $entries[3]['label']);

        Gaze::rateLimiter()->clear();
        gaze('after clear');
        Gaze::client()->flush();
        $this->assertCount(5, $this->entries());
        $this->assertBatchesValid();
    }

    public function testIfShowWhenAndRemove(): void
    {
        gaze()->if(false)->send('never')->red();
        gaze()->if(fn () => true)->send('yes');
        gaze()->if(true, fn (Gaze $g) => $g->send('from callback'));
        gaze('shown')->showWhen(true);
        gaze('hidden')->showWhen(false);
        gaze('removed')->removeIf(fn () => true);
        Gaze::client()->flush();

        $entries = $this->entries();
        $this->assertSame(['add', 'add', 'add', 'add', 'remove', 'add', 'remove'], array_column($entries, 'op'));
        $this->assertSame($entries[3]['id'], $entries[4]['id']);
        $this->assertBatchesValid();
    }

    public function testExplicitIdsForUpdateAndAppend(): void
    {
        $id = gaze()->html('<p>v1</p>')->id();
        gaze()->update($id)->html('<p>v2</p>');
        gaze()->append($id)->html('<p>iteration</p>');
        gaze()->update($id, 'as log');
        gaze()->update('short')->text('padded id');
        Gaze::client()->flush();

        $entries = $this->entries();
        $this->assertSame(['add', 'update', 'append', 'update', 'update'], array_column($entries, 'op'));
        $this->assertCount(2, array_unique(array_column($entries, 'id')));
        $this->assertSame('short___', $entries[4]['id']);
        $this->assertBatchesValid();
    }

    public function testTraceContextAndGroups(): void
    {
        $traceId = Gaze::startTrace('GET /orders/5', 'http');
        gaze('in request');
        $result = gaze()->group('import', function () {
            gaze('inside group');

            return 7;
        });
        gaze('after group');
        $traceparent = Gaze::traceparent();
        Gaze::setTraceContext(null);
        gaze('no trace');
        Gaze::client()->flush();

        $this->assertSame(7, $result);
        $this->assertMatchesRegularExpression('/^00-' . $traceId . '-[0-9a-f]{16}-01$/', (string) $traceparent);
        $entries = [];
        foreach ($this->batches() as $batch) {
            foreach ($batch['entries'] as $e) {
                $e['trace'] ??= $batch['trace'] ?? null;
                $entries[] = $e;
            }
        }
        $byText = [];
        foreach ($entries as $e) {
            $byText[$e['body']['values'][0]['v'] ?? $e['kind']] = $e;
        }
        $this->assertSame($traceId, $byText['in request']['trace']['id']);
        $this->assertSame('http', $byText['in request']['trace']['kind']);
        $group = $byText['inside group']['trace'];
        $this->assertNotSame($traceId, $group['id']);
        $this->assertSame($traceId, $group['parent']);
        $this->assertSame('import', $group['name']);
        $this->assertSame($group['id'], $byText['group']['trace']['id']);
        $this->assertTrue($byText['group']['trace']['ended']);
        $this->assertSame('import', $byText['group']['body']['name']);
        $this->assertSame($traceId, $byText['after group']['trace']['id']);
        $this->assertNull($byText['no trace']['trace']);
        $this->assertBatchesValid();

        $this->assertSame('0af7651916cd43dd8448eb211c80319c', Gaze::continueTrace('00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01', 'GET /'));
        $this->assertSame('b7ad6b7169203331', Gaze::traceContext()['parent']);
        $this->assertNull(Gaze::continueTrace('garbage', 'x'));
    }

    public function testTraceInputIsKeptSchemaValid(): void
    {
        $id = Gaze::startTrace('custom unit', 'not-a-kind', 'zz');
        gaze()->groupEnd(); // no group open: must not pop the unit's trace
        gaze('inside');
        Gaze::setTraceContext(['id' => 'short', 'kind' => 'job', 'parent' => 'nope', 'name' => 'SendMail']);
        gaze('job');
        Gaze::client()->flush();

        $batches = $this->batches();
        $traces = [];
        foreach ($batches as $b) {
            foreach ($b['entries'] as $e) {
                $traces[] = $e['trace'] ?? $b['trace'] ?? null;
            }
        }
        $this->assertCount(2, $traces);
        $this->assertSame(['id' => $id, 'kind' => 'other'], array_intersect_key($traces[0], ['id' => 1, 'kind' => 1]));
        $this->assertArrayNotHasKey('parent', $traces[0]);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $traces[1]['id']);
        $this->assertSame('job', $traces[1]['kind']);
        $this->assertArrayNotHasKey('parent', $traces[1]);
        $this->assertBatchesValid();
    }

    public function testMacros(): void
    {
        Gaze::macro('shout', function (string $text): Gaze {
            /** @var Gaze $this */
            return $this->text(strtoupper($text))->orange();
        });
        try {
            gaze()->shout('hi');
            Gaze::client()->flush();
            $e = $this->entries()[0];
            $this->assertSame(['HI', 'orange'], [$e['body']['text'], $e['color']]);
            $this->assertTrue(Gaze::hasMacro('shout'));
        } finally {
            Gaze::flushMacros();
        }
        $this->expectException(\BadMethodCallException::class);
        gaze()->nope();
    }

    public function testProjectNameGoesIntoTheEnvelope(): void
    {
        gaze()->project('shop')->send('x');
        Gaze::client()->flush();
        $this->assertSame('shop', $this->batches()[0]['client']['project']['name']);
    }

    private function throwing(int &$line): never
    {
        $previous = new \LogicException('gateway timeout');
        $line = __LINE__ + 1;
        throw new \RuntimeException('payment failed', 42, $previous);
    }
}
