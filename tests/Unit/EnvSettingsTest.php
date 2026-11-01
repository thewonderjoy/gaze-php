<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Tests\Unit;

use TheWonderJoy\Gaze\Config;
use TheWonderJoy\Gaze\Gaze;
use TheWonderJoy\Gaze\Tests\TestCase;
use TheWonderJoy\Gaze\Transport\HostResolver;

/** The environment variables of the README "Settings" section, and how configure() sits on top. */
final class EnvSettingsTest extends TestCase
{
    private const NAMES = ['GAZE_ENABLED', 'GAZE_HOST', 'GAZE_PORT', 'GAZE_TOKEN', 'GAZE_PROJECT', 'RAY_HOST', 'RAY_PORT', 'APP_ENV', 'LARAVEL_SAIL'];

    /** @var array<string, array{0: mixed, 1: mixed, 2: string|false}> */
    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::NAMES as $name) {
            $this->saved[$name] = [$_SERVER[$name] ?? null, $_ENV[$name] ?? null, getenv($name)];
            $this->env([$name => null]);
        }
        Gaze::reset();
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $name => [$server, $env, $put]) {
            if ($server === null) {
                unset($_SERVER[$name]);
            } else {
                $_SERVER[$name] = $server;
            }
            if ($env === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $env;
            }
            putenv($put === false ? $name : "$name=$put");
        }
        Gaze::reset();
        parent::tearDown();
    }

    /** @param array<string, string|null> $vars set in $_SERVER, $_ENV and putenv; null unsets */
    private function env(array $vars): void
    {
        foreach ($vars as $name => $value) {
            if ($value === null) {
                unset($_SERVER[$name], $_ENV[$name]);
                putenv($name);
            } else {
                $_SERVER[$name] = $_ENV[$name] = $value;
                putenv("$name=$value");
            }
        }
        Config::reset();
    }

    public function testEveryEnvVarReachesItsSetting(): void
    {
        $this->env(['GAZE_HOST' => 'gaze.test', 'GAZE_PORT' => '23700', 'GAZE_TOKEN' => 's3cret', 'GAZE_PROJECT' => 'shop']);
        $this->assertSame(['gaze.test', 23700, 's3cret', 'shop'], [Config::get('host'), Config::get('port'), Config::get('token'), Config::get('project')]);

        $this->env(['RAY_HOST' => 'ray.test', 'RAY_PORT' => '23701']);
        $this->assertSame(['gaze.test', 23700], [Config::get('host'), Config::get('port')], 'GAZE_* wins over RAY_*');

        $this->env(['GAZE_HOST' => null, 'GAZE_PORT' => null]);
        $this->assertSame(['ray.test', 23701], [Config::get('host'), Config::get('port')], 'RAY_* is the fallback');

        $this->env(['GAZE_HOST' => '', 'GAZE_PORT' => '  ']);
        $this->assertSame(['ray.test', 23701], [Config::get('host'), Config::get('port')], 'a blank GAZE_* counts as unset');
    }

    public function testConfigureWinsOverTheEnvironment(): void
    {
        $this->env(['GAZE_HOST' => 'gaze.test', 'GAZE_PORT' => '23700', 'GAZE_TOKEN' => 's3cret', 'GAZE_PROJECT' => 'shop', 'GAZE_ENABLED' => 'false']);
        Gaze::configure(['host' => 'set.test', 'port' => 23702, 'token' => 'mine', 'project' => 'api', 'enabled' => true]);
        $this->assertSame(['set.test', 23702, 'mine', 'api', true], [Config::get('host'), Config::get('port'), Config::get('token'), Config::get('project'), Config::get('enabled')]);
    }

    public function testConfigureWithNullLeavesEachSettingToTheEnvironment(): void
    {
        $this->env(['GAZE_HOST' => 'gaze.test', 'GAZE_PORT' => '23700', 'GAZE_TOKEN' => 's3cret', 'GAZE_ENABLED' => 'false']);
        Gaze::configure(['host' => null, 'port' => null, 'token' => null]);
        $this->assertSame(['gaze.test', 23700, 's3cret'], [Config::get('host'), Config::get('port'), Config::get('token')]);
        Gaze::configure(['enabled' => null]);
        $this->assertFalse(Config::get('enabled'), 'enabled => null leaves GAZE_ENABLED in charge, like host, port and token');
    }

    public function testGazeEnabledValues(): void
    {
        foreach (['false', 'FALSE', '0', 'off', 'no', 'disabled', '(false)'] as $value) {
            $this->env(['APP_ENV' => 'local', 'GAZE_ENABLED' => $value]);
            $this->assertFalse(Config::get('enabled'), "GAZE_ENABLED=$value");
        }
        foreach (['true', '1', 'on', 'yes'] as $value) {
            $this->env(['APP_ENV' => 'production', 'GAZE_ENABLED' => $value]);
            $this->assertTrue(Config::get('enabled'), "GAZE_ENABLED=$value in production");
        }
        $this->env(['APP_ENV' => 'production', 'GAZE_ENABLED' => '']);
        $this->assertFalse(Config::get('enabled'), 'a blank GAZE_ENABLED counts as unset: off in production');
        $this->env(['APP_ENV' => 'local']);
        $this->assertTrue(Config::get('enabled'), 'a blank GAZE_ENABLED counts as unset: on elsewhere');
    }

    public function testAValueOnlyInServerOrEnvIsRead(): void
    {
        $_ENV['GAZE_PORT'] = '23703';
        Config::reset();
        $this->assertSame(23703, Config::get('port'), '$_ENV only (a dotenv loader without putenv)');
        unset($_ENV['GAZE_PORT']);
        $_SERVER['GAZE_PORT'] = '23704';
        Config::reset();
        $this->assertSame(23704, Config::get('port'), '$_SERVER only');
    }

    public function testAPortOutsideOneTo65535IsTheDefault(): void
    {
        foreach (['0', '-1', '65536', 'abc', '23517.9x'] as $value) {
            $this->env(['GAZE_PORT' => $value]);
            $this->assertSame(23517, Config::get('port'), "GAZE_PORT=$value");
        }
        $this->env(['GAZE_PORT' => '65535']);
        $this->assertSame(65535, Config::get('port'));
    }

    public function testLaravelSailAddsTheDockerHost(): void
    {
        $root = self::root() . DIRECTORY_SEPARATOR . 'fs-' . bin2hex(random_bytes(4));
        @mkdir($root, 0777, true);
        $this->assertSame(['127.0.0.1'], HostResolver::candidates($root, false));
        $this->env(['LARAVEL_SAIL' => '1']);
        $this->assertSame(['127.0.0.1', 'host.docker.internal'], HostResolver::candidates($root, false));
        $this->assertSame(['127.0.0.1'], HostResolver::candidates($root, true), 'native Windows: this machine only');
    }

    public function testDiscoverFalseUsesLoopbackWithoutProbing(): void
    {
        $this->env(['LARAVEL_SAIL' => '1']);
        Gaze::configure(['discover' => false]);
        $started = microtime(true);
        $this->assertSame('127.0.0.1', HostResolver::resolve(23619));
        $this->assertLessThan(0.1, microtime(true) - $started, 'no probe');
        Gaze::configure(['discover' => true, 'host' => 'set.test']);
        $this->assertSame('set.test', HostResolver::resolve(23619), 'a configured host is used as is');
    }
}
