<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Tests\Unit;

use TheWonderJoy\Gaze\Client;
use TheWonderJoy\Gaze\Gaze;
use TheWonderJoy\Gaze\Tests\Fixtures\Address;
use TheWonderJoy\Gaze\Tests\Fixtures\Plain;
use TheWonderJoy\Gaze\Tests\Fixtures\Suit;
use TheWonderJoy\Gaze\Tests\Fixtures\User;
use TheWonderJoy\Gaze\Tests\TestCase;
use TheWonderJoy\Gaze\Value\ValueFactory as V;

final class ValueFactoryTest extends TestCase
{
    public function testScalars(): void
    {
        $this->assertSame(['k' => 'null'], V::from(null));
        $this->assertSame(['k' => 'bool', 'v' => true], V::from(true));
        $this->assertSame(['k' => 'int', 'v' => 42], V::from(42));
        $this->assertSame(['k' => 'float', 'v' => 3.14], V::from(3.14));
        $this->assertSame(['k' => 'str', 'v' => 'naमस्ते 🌏'], V::from('naमस्ते 🌏'));
    }

    public function testIntegersBeyondTwoToThe53AreStrings(): void
    {
        $this->assertSame(['k' => 'int', 'v' => 9007199254740991], V::from(9007199254740991));
        $this->assertSame(['k' => 'int', 'v' => '9007199254740992'], V::from(9007199254740992));
        $this->assertSame(['k' => 'int', 'v' => '9223372036854775807'], V::from(PHP_INT_MAX));
        $this->assertSame(['k' => 'int', 'v' => '-9223372036854775808'], V::from(PHP_INT_MIN));
    }

    public function testNonFiniteFloatsAndNegativeZeroAreStrings(): void
    {
        $this->assertSame(['k' => 'float', 'v' => 'NaN'], V::from(NAN));
        $this->assertSame(['k' => 'float', 'v' => 'INF'], V::from(INF));
        $this->assertSame(['k' => 'float', 'v' => '-INF'], V::from(-INF));
        $this->assertSame(['k' => 'float', 'v' => '-0'], V::from(-0.0));
        $this->assertSame(['k' => 'float', 'v' => 0.0], V::from(0.0));
        // also inside containers that go through VarCloner
        $tree = V::from([new \stdClass(), NAN, -0.0, PHP_INT_MAX]);
        $this->assertSame(['k' => 'float', 'v' => 'NaN'], $tree['items'][1]);
        $this->assertSame(['k' => 'float', 'v' => '-0'], $tree['items'][2]);
        $this->assertSame(['k' => 'int', 'v' => '9223372036854775807'], $tree['items'][3]);
    }

    public function testBinaryStringsAreBase64(): void
    {
        $this->assertSame(['k' => 'str', 'v' => base64_encode("\xff\xfe\x00raw"), 'enc' => 'b64'], V::from("\xff\xfe\x00raw"));
        $tree = V::from([new \stdClass(), "\xff\x00"]);
        $this->assertSame(['k' => 'str', 'v' => base64_encode("\xff\x00"), 'enc' => 'b64'], $tree['items'][1]);
    }

    public function testLongStringsAreCutAt16KiBCharacters(): void
    {
        $s = str_repeat('é', 20000);
        $node = V::from($s);
        $this->assertSame(16384, mb_strlen($node['v']));
        $this->assertSame(20000 - 16384, $node['cut']);
        $this->assertSame(20000, $node['len']);
        // same result through the cloner path
        $viaCloner = V::from([new \stdClass(), $s])['items'][1];
        $this->assertSame($node, $viaCloner);
    }

    public function testListsAndMaps(): void
    {
        $this->assertSame(['k' => 'list', 'count' => 3, 'items' => [['k' => 'int', 'v' => 1], ['k' => 'int', 'v' => 2], ['k' => 'int', 'v' => 3]]], V::from([1, 2, 3]));
        $this->assertSame(
            ['k' => 'map', 'count' => 3, 'entries' => [['a', ['k' => 'int', 'v' => 1]], ['b', ['k' => 'str', 'v' => 'two']], ['c', ['k' => 'bool', 'v' => true]]]],
            V::from(['a' => 1, 'b' => 'two', 'c' => true]),
        );
        $this->assertSame(['k' => 'map', 'count' => 2, 'entries' => [[5, ['k' => 'int', 'v' => 1]], [9, ['k' => 'int', 'v' => 2]]]], V::from([5 => 1, 9 => 2]));
        $this->assertSame(['k' => 'list', 'count' => 0, 'items' => []], V::from([]));
    }

    public function testPlainPathAndClonerPathAgreeOnArrayShapes(): void
    {
        $data = ['n' => [1, 2, ['x' => null]], 's' => 'v', 'f' => 1.5];
        $plain = V::from($data);
        $cloned = V::from([$data, new \stdClass()])['items'][0];
        $this->assertSame($plain, $cloned);
    }

    public function testObjectsWithVisibilityDeclaringClassAndSelfReference(): void
    {
        $tree = V::from(new User());
        $this->assertSame('obj', $tree['k']);
        $this->assertSame(User::class, $tree['cls']);
        $this->assertSame(1, $tree['id']);
        $props = array_column($tree['props'], null, 'n');
        $this->assertSame('pub', $props['id']['vis']);
        $this->assertSame(['k' => 'int', 'v' => 42], $props['id']['val']);
        $this->assertSame('prot', $props['roles']['vis']);
        $this->assertSame('priv', $props['address']['vis']);
        $this->assertSame(User::class, $props['address']['decl']);
        $this->assertSame(Address::class, $props['address']['val']['cls']);
        $geo = array_column($props['address']['val']['props'], null, 'n')['geo'];
        $this->assertSame(['vis' => 'priv', 'decl' => Address::class], ['vis' => $geo['vis'], 'decl' => $geo['decl']]);
        $this->assertSame(['k' => 'ref', 'to' => 1, 'cls' => User::class], $props['self']['val']);
        $this->assertValidValue($tree);
    }

    public function testRepeatedObjectsBecomeRefsAndDynamicPropsAreMarked(): void
    {
        $o = new \stdClass();
        $o->a = 1;
        $tree = V::from([$o, $o]);
        $this->assertSame(['k' => 'obj', 'cls' => 'stdClass', 'id' => 1, 'props' => [['n' => 'a', 'vis' => 'dyn', 'val' => ['k' => 'int', 'v' => 1]]]], $tree['items'][0]);
        $this->assertSame(['k' => 'ref', 'to' => 1, 'cls' => 'stdClass'], $tree['items'][1]);
    }

    public function testEnums(): void
    {
        $this->assertSame(['k' => 'const', 'v' => Suit::class . '::Hearts', 'cls' => Suit::class, 'val' => ['k' => 'str', 'v' => 'H']], V::from(Suit::Hearts));
        $this->assertSame(['k' => 'const', 'v' => Plain::class . '::A', 'cls' => Plain::class], V::from(Plain::A));
        // a repeated case is the same constant again, not a ref
        $tree = V::from([Suit::Hearts, Suit::Hearts]);
        $this->assertSame($tree['items'][0], $tree['items'][1]);
    }

    public function testDates(): void
    {
        $date = new \DateTimeImmutable('2026-10-31 12:00:00', new \DateTimeZone('Asia/Kolkata'));
        $this->assertSame(['k' => 'date', 'iso' => '2026-10-31T12:00:00+05:30', 'tz' => 'Asia/Kolkata', 'cls' => \DateTimeImmutable::class], V::from($date));
        $micro = new \DateTime('2026-01-02 03:04:05.123456', new \DateTimeZone('UTC'));
        $this->assertSame('2026-01-02T03:04:05.123456+00:00', V::from($micro)['iso']);
    }

    public function testClosuresAndResources(): void
    {
        $fn = V::from(fn (int $x): int => $x * 2);
        $this->assertSame('fn', $fn['k']);
        $this->assertStringContainsString('int $x', $fn['name']);
        $this->assertSame(__FILE__, $fn['file']);
        $this->assertSame(__LINE__ - 4, $fn['line']);

        $res = V::from(fopen('php://memory', 'r'));
        $this->assertSame('res', $res['k']);
        $this->assertSame('stream', $res['rt']);
        $this->assertContains('uri', array_column($res['props'], 'n'));
        $this->assertValidValue($fn);
        $this->assertValidValue($res);
    }

    public function testDepthLimit(): void
    {
        $deep = ['l1' => ['l2' => ['l3' => ['l4' => ['l5' => ['l6' => ['l7' => ['l8' => ['l9' => 1]]]]]]]]];
        $node = V::from($deep);
        for ($i = 1; $i <= 8; ++$i) {
            $this->assertArrayNotHasKey('cut', $node);
            $node = $node['entries'][0][1];
        }
        $this->assertSame(['k' => 'map', 'count' => 1, 'entries' => [], 'cut' => 1], $node);

        // through the cloner: the root list is level 1, so $deep starts one level down
        $node = V::from([new \stdClass(), $deep])['items'][1];
        for ($i = 1; $i <= 7; ++$i) {
            $this->assertArrayNotHasKey('cut', $node);
            $node = $node['entries'][0][1];
        }
        $this->assertSame(['k' => 'map', 'count' => 1, 'entries' => [], 'cut' => 1], $node);
    }

    public function testItemsPerCollectionAreCutIndependently(): void
    {
        $tree = V::from(range(1, 300));
        $this->assertSame(300, $tree['count']);
        $this->assertCount(200, $tree['items']);
        $this->assertSame(100, $tree['cut']);

        // through the cloner: a cut in one collection must not cut its siblings
        $tree = V::from([['x' => range(1, 250)], ['y' => 2], new \stdClass()]);
        $this->assertSame(50, $tree['items'][0]['entries'][0][1]['cut']);
        $this->assertSame([['y', ['k' => 'int', 'v' => 2]]], $tree['items'][1]['entries']);

        $objects = [];
        for ($i = 0; $i < 300; ++$i) {
            $objects[] = new \stdClass();
        }
        $tree = V::from($objects);
        $this->assertCount(200, $tree['items']);
        $this->assertSame(100, $tree['cut']);
    }

    public function testNodeBudgetBoundsHugeValues(): void
    {
        $big = [];
        for ($i = 0; $i < 100; ++$i) {
            $big[] = array_fill(0, 100, new \stdClass());
        }
        $t = hrtime(true);
        $tree = V::from($big);
        $ms = (hrtime(true) - $t) / 1e6;
        $this->assertSame(100, $tree['count']);
        $this->assertLessThan(500, $ms);
        $this->assertValidValue($tree);
    }

    public function testConfiguredLimits(): void
    {
        Gaze::configure(['limits' => ['string' => 5, 'items' => 2, 'depth' => 2]]);
        $this->assertSame(['k' => 'str', 'v' => 'abcde', 'cut' => 2, 'len' => 7], V::from('abcdefg'));
        $this->assertSame(1, V::from([1, 2, 3])['cut']);
        $this->assertSame(1, V::from(['a' => ['b' => ['c' => 1]]])['entries'][0][1]['entries'][0][1]['cut']);
    }

    public function testServerLimitsOnlyLowerTheConfiguredOnes(): void
    {
        V::applyServerLimits(['max_string_chars' => 4]);
        $this->assertSame(3, V::from('abcdefg')['cut']);
        V::applyServerLimits(['max_string_chars' => 1 << 30]);
        $this->assertSame(20000 - 16384, V::from(str_repeat('x', 20000))['cut']);
    }

    public function testJsonDataKeepsObjectsAndListsApart(): void
    {
        $node = V::fromPlain(json_decode('{"a":[1,{"b":null}],"e":{}}'));
        $this->assertSame('map', $node['k']);
        $this->assertSame('list', $node['entries'][0][1]['k']);
        $this->assertSame('map', $node['entries'][0][1]['items'][1]['k']);
        $this->assertSame(['k' => 'map', 'count' => 0, 'entries' => []], $node['entries'][1][1]);
    }

    public function testConversionNeverThrows(): void
    {
        $evil = new class () {
            public function __debugInfo(): array
            {
                throw new \LogicException('nope');
            }
        };
        $node = V::from($evil);
        $this->assertSame('obj', $node['k']);
        $this->assertValidValue($node);
    }

    /** @param array<string, mixed> $value */
    private function assertValidValue(array $value): void
    {
        $json = Client::instance()->encode([['op' => 'add', 'id' => '00000000-0000-7000-8000-000000000000', 'seq' => 0, 'kind' => 'log', 'body' => ['values' => [$value]]]]);
        self::assertValidBatchJson($json);
    }
}
