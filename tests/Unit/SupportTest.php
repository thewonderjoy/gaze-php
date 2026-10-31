<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Tests\Unit;

use TheWonderJoy\Gaze\Client;
use TheWonderJoy\Gaze\Config;
use TheWonderJoy\Gaze\Gaze;
use TheWonderJoy\Gaze\Support\Origin;
use TheWonderJoy\Gaze\Support\StateFile;
use TheWonderJoy\Gaze\Support\Uuid;
use TheWonderJoy\Gaze\Tests\TestCase;
use TheWonderJoy\Gaze\Transport\HostResolver;

final class SupportTest extends TestCase
{
    private const UUID7 = '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    public function testUuidV7FormatUniquenessAndTimeOrder(): void
    {
        $ids = [];
        for ($i = 0; $i < 2000; ++$i) {
            $ids[] = Uuid::v7();
        }
        foreach ($ids as $id) {
            $this->assertMatchesRegularExpression(self::UUID7, $id);
        }
        $this->assertCount(2000, array_unique($ids));
        $a = Uuid::v7();
        usleep(2000);
        $b = Uuid::v7();
        $this->assertLessThan(0, strcmp($a, $b), 'ids from later milliseconds sort later');
        $ms = (int) hexdec(str_replace('-', '', substr($b, 0, 13)));
        $this->assertEqualsWithDelta(microtime(true) * 1000, $ms, 1000);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', Uuid::traceId());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', Uuid::spanId());
    }

    public function testGatewayFromProcNetRoute(): void
    {
        $table = "Iface\tDestination\tGateway \tFlags\tRefCnt\tUse\tMetric\tMask\t\tMTU\tWindow\tIRTT\n"
            . "eth0\t00000000\t01F01EAC\t0003\t0\t0\t0\t00000000\t0\t0\t0\n"
            . "eth0\t00F01EAC\t00000000\t0001\t0\t0\t0\t00F0FFFF\t0\t0\t0\n";
        $this->assertSame('172.30.240.1', HostResolver::gateway($table));
        $this->assertNull(HostResolver::gateway("Iface\tDestination\tGateway\neth0\t00F01EAC\t00000000\n"));
        $this->assertNull(HostResolver::gateway(''));
    }

    public function testCandidates(): void
    {
        $candidates = HostResolver::candidates(__DIR__ . '/missing-dockerenv', __DIR__ . '/missing-route');
        $this->assertSame(HostResolver::DEFAULT_HOST, $candidates[0]);
        $this->assertSame('127.0.0.1', HostResolver::DEFAULT_HOST);
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->assertSame(['127.0.0.1'], $candidates, 'native Windows probes nothing');
        }
    }

    public function testLoopbackAndBaseUrl(): void
    {
        foreach (['localhost', '127.0.0.1', '127.1.2.3', '::1', '[::1]', 'app.localhost'] as $host) {
            $this->assertTrue(HostResolver::isLoopback($host), $host);
        }
        foreach (['host.docker.internal', '172.30.240.1', '192.168.1.10', 'example.test'] as $host) {
            $this->assertFalse(HostResolver::isLoopback($host), $host);
        }
        $this->assertSame('http://[::1]:23517', HostResolver::baseUrl('::1', 23517));
        $this->assertSame('http://127.0.0.1:1', HostResolver::baseUrl('127.0.0.1', 1));
    }

    public function testBearerTokenOnlyForNonLoopbackHosts(): void
    {
        $this->assertNotContains('Authorization: Bearer s3cret', Client::headersFor('localhost', 's3cret'));
        $this->assertNotContains('Authorization: Bearer s3cret', Client::headersFor('127.0.0.1', 's3cret'));
        $this->assertContains('Authorization: Bearer s3cret', Client::headersFor('172.30.240.1', 's3cret'));
        $this->assertStringNotContainsString('Authorization', implode("\n", Client::headersFor('172.30.240.1', null)));
        $this->assertContains('Expect:', Client::headersFor('localhost', null), 'no 100-continue round trip');
    }

    public function testConfigPrecedence(): void
    {
        Gaze::reset();
        $restore = [];
        foreach (['GAZE_PORT' => '23001', 'RAY_PORT' => '23002', 'RAY_HOST' => 'ray-host', 'GAZE_ENABLED' => 'false'] as $k => $v) {
            $restore[$k] = getenv($k);
            putenv("$k=$v");
        }
        try {
            Config::reset();
            $this->assertSame(23001, Config::get('port'), 'GAZE_PORT wins over RAY_PORT');
            $this->assertSame('ray-host', Config::get('host'), 'RAY_HOST is the fallback');
            $this->assertFalse(Config::get('enabled'));
            $this->assertFalse(Gaze::make()->enabled());
            putenv('GAZE_PORT');
            Config::reset();
            $this->assertSame(23002, Config::get('port'));
            Gaze::configure(['port' => 23003, 'enabled' => true]);
            $this->assertSame(23003, Config::get('port'), 'configure() wins over the environment');
            $this->assertTrue(Config::get('enabled'));
            putenv('RAY_PORT=nonsense');
            Config::reset();
            $this->assertSame(23517, Config::get('port'));
        } finally {
            foreach ($restore as $k => $v) {
                putenv($v === false ? $k : "$k=$v");
            }
            Gaze::reset();
        }
    }

    public function testOffByDefaultWhenAppEnvIsProduction(): void
    {
        Gaze::reset();
        $restore = ['APP_ENV' => getenv('APP_ENV'), 'GAZE_ENABLED' => getenv('GAZE_ENABLED')];
        try {
            foreach (['production' => false, 'prod' => false, 'PRODUCTION' => false, 'local' => true, 'staging' => true] as $env => $on) {
                putenv("APP_ENV=$env");
                Config::reset();
                $this->assertSame($on, Config::get('enabled'), "APP_ENV=$env");
            }
            putenv('APP_ENV=production');
            putenv('GAZE_ENABLED=true');
            Config::reset();
            $this->assertTrue(Config::get('enabled'), 'GAZE_ENABLED wins over APP_ENV');
            putenv('GAZE_ENABLED');
            Gaze::configure(['enabled' => true]);
            $this->assertTrue(Config::get('enabled'), 'configure() wins over APP_ENV');
            Gaze::reset();
            $this->assertFalse(Gaze::make()->enabled(), 'gaze() is inert in production');
            putenv('APP_ENV');
            Config::reset();
            $this->assertTrue(Config::get('enabled'), 'no APP_ENV: on');
        } finally {
            foreach ($restore as $k => $v) {
                putenv($v === false ? $k : "$k=$v");
            }
            Gaze::reset();
        }
    }

    public function testBooleanParsing(): void
    {
        foreach (['false', 'FALSE', '0', 'off', 'no', '(false)', ''] as $v) {
            $this->assertFalse(Config::bool($v, true), $v);
        }
        foreach (['true', '1', 'on', 'yes'] as $v) {
            $this->assertTrue(Config::bool($v, false), $v);
        }
        $this->assertTrue(Config::bool(null, true));
    }

    public function testOriginIsTheFirstFrameOutsideThePackage(): void
    {
        [$origin] = (fn () => Origin::capture(0))();
        $this->assertSame(__FILE__, $origin['file']);
        $this->assertSame(__LINE__ - 2, $origin['line']);
        $this->assertTrue(Origin::isInternal(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Gaze.php'));
        $this->assertFalse(Origin::isInternal(__FILE__));
        $this->assertTrue(Origin::isVendor('/var/www/vendor/laravel/framework/src/x.php'));
        $this->assertTrue(Origin::isVendor('C:\\app\\vendor\\x\\y.php'));
        $this->assertFalse(Origin::isVendor('/var/www/app/Http/Kernel.php'));
    }

    public function testStateFileRoundTrip(): void
    {
        $key = StateFile::endpointKey('LocalHost', 23517);
        $this->assertSame($key, StateFile::endpointKey('localhost', 23517));
        StateFile::write($key, ['down_until' => 123]);
        $this->assertSame(['down_until' => 123], StateFile::read($key));
        $this->assertStringStartsWith($this->stateDir, StateFile::path($key));
        StateFile::delete($key);
        $this->assertNull(StateFile::read($key));
    }

    public function testHelpersDoNotDefineRay(): void
    {
        $r = $this->runPhp('echo json_encode([function_exists("gaze"), function_exists("gaze_dd"), function_exists("ray"), function_exists("rd")]);');
        $this->assertSame('[true,true,false,false]', $r['out'], $r['err']);
    }

    public function testRegisterRayHelperNeverShadowsAnExistingRay(): void
    {
        $r = $this->runPhp(<<<'PHP'
            function ray(...$a) { return 'theirs'; }
            $registered = \TheWonderJoy\Gaze\Gaze::registerRayHelper();
            echo json_encode([$registered, ray(), function_exists('rd')]);
            PHP);
        $this->assertSame('[true,"theirs",true]', $r['out'], $r['err']);

        $r = $this->runPhp(<<<'PHP'
            \TheWonderJoy\Gaze\Gaze::configure(['enabled' => false]);
            $registered = \TheWonderJoy\Gaze\Gaze::registerRayHelper();
            echo json_encode([$registered, get_class(ray('x')), \TheWonderJoy\Gaze\Gaze::registerRayHelper()]);
            PHP);
        $this->assertSame('[true,"TheWonderJoy\\\\Gaze\\\\Noop",false]', $r['out'], $r['err']);
    }

    public function testSchemaCopyMatchesTheProtocolFolder(): void
    {
        $monorepo = dirname(__DIR__, 4) . '/protocol/schema/batch.schema.json';
        if (!is_file($monorepo)) {
            $this->markTestSkipped('not inside the monorepo');
        }
        $this->assertSame(
            json_decode((string) file_get_contents($monorepo), true),
            json_decode((string) file_get_contents(self::schemaPath()), true),
            'tests/schema/batch.schema.json is stale: copy protocol/schema/batch.schema.json',
        );
    }
}
