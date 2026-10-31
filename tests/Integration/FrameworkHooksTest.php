<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Tests\Integration;

use TheWonderJoy\Gaze\Gaze;
use TheWonderJoy\Gaze\Tests\TestCase;

/** Hooks used by framework packages (thewonderjoy/gaze-laravel): explicit origins and the cached hello. */
final class FrameworkHooksTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        self::startServer();
    }

    public function testCustomKeepsAnOriginPassedAsModifier(): void
    {
        $origin = ['file' => '/var/www/html/app/Http/Controllers/OrderController.php', 'line' => 42, 'function' => 'show'];
        gaze()->custom('request', ['method' => 'GET', 'status' => 200], ['origin' => $origin]);
        gaze()->custom('request', ['method' => 'GET', 'status' => 201]);
        Gaze::client()->flush();

        $entries = $this->entries();
        $this->assertSame($origin, $entries[0]['origin']);
        $this->assertSame(__FILE__, $entries[1]['origin']['file'], 'without one, the call site is captured as before');
        $this->assertBatchesValid();
    }

    public function testHelloExposesTheHandshakeAndIsNullWhenUnreachableOrDisabled(): void
    {
        $hello = Gaze::hello();
        $this->assertIsArray($hello);
        $this->assertSame(1, $hello['v']);
        $this->assertSame('fake-app', $hello['server']['name']);
        $this->assertSame(1, count(array_filter($this->requests(), static fn ($r) => $r['path'] === '/v1/hello')));
        Gaze::hello();
        $this->assertSame(1, count(array_filter($this->requests(), static fn ($r) => $r['path'] === '/v1/hello')), 'cached');

        Gaze::configure(['port' => self::CLOSED_PORT]);
        $this->assertNull(Gaze::hello());

        Gaze::configure(['port' => self::PORT, 'enabled' => false]);
        $this->assertNull(Gaze::hello());
    }
}
