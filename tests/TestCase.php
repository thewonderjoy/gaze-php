<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Tests;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use TheWonderJoy\Gaze\Gaze;

/**
 * Starts one fake app (tests/Fixtures/fake-app.php under `php -S`) for the whole run and
 * kills it at shutdown. Every test gets a clean request log, clean control file and a fresh
 * temp state directory, so the shared "down"/handshake caches never leak between tests.
 */
abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    public const PORT = 23610;
    public const CLOSED_PORT = 23619;
    public const SCHEMA_ID = 'https://example.invalid/protocol/v1/batch.schema.json';

    /** @var resource|null */
    private static $server = null;

    private static ?string $root = null;

    private static ?Validator $validator = null;

    protected string $stateDir;

    /** @var list<string> */
    protected array $logs = [];

    public static function root(): string
    {
        if (self::$root === null) {
            self::$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gaze-php-tests-' . getmypid();
            @mkdir(self::$root, 0777, true);
            @mkdir(self::$root . '/server', 0777, true);
            register_shutdown_function(static function (): void {
                self::stopServer();
                self::rmrf((string) self::$root);
            });
        }

        return self::$root;
    }

    public static function serverDir(): string
    {
        return self::root() . DIRECTORY_SEPARATOR . 'server';
    }

    public static function startServer(): void
    {
        if (self::$server !== null) {
            return;
        }
        $probe = @stream_socket_server('tcp://127.0.0.1:' . self::PORT, $errno, $errstr);
        if ($probe === false) {
            throw new \RuntimeException('Port ' . self::PORT . ' is in use; the fake app needs it.');
        }
        fclose($probe);

        $env = getenv();
        $env['GAZE_FAKE_DIR'] = self::serverDir();
        $log = self::root() . DIRECTORY_SEPARATOR . 'php-s.log';
        self::$server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::PORT, __DIR__ . '/Fixtures/fake-app.php'],
            [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $pipes,
            null,
            $env,
        );
        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            $fp = @fsockopen('127.0.0.1', self::PORT, $errno, $errstr, 0.2);
            if ($fp !== false) {
                fclose($fp);

                return;
            }
            usleep(20000);
        }
        self::stopServer();
        throw new \RuntimeException('The fake app did not start on port ' . self::PORT);
    }

    public static function stopServer(): void
    {
        if (self::$server !== null) {
            proc_terminate(self::$server);
            proc_close(self::$server);
            self::$server = null;
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->stateDir = self::root() . DIRECTORY_SEPARATOR . 'state-' . bin2hex(random_bytes(4));
        @mkdir($this->stateDir, 0777, true);
        @unlink(self::serverDir() . '/requests.ndjson');
        @unlink(self::serverDir() . '/control.json');
        foreach (glob(self::serverDir() . '/lock-polls-*') ?: [] as $f) {
            @unlink($f);
        }
        $this->logs = [];
        Gaze::reset();
        Gaze::configure($this->baseConfig());
    }

    protected function tearDown(): void
    {
        Gaze::reset();
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    protected function baseConfig(int $port = self::PORT): array
    {
        return [
            'enabled' => true,
            'host' => '127.0.0.1',
            'port' => $port,
            'state_dir' => $this->stateDir,
            'logger' => function (string $message): void {
                $this->logs[] = $message;
            },
        ];
    }

    /** @param array<string, mixed> $control */
    protected function control(array $control): void
    {
        file_put_contents(self::serverDir() . '/control.json', json_encode($control));
    }

    /** @return list<array<string, mixed>> every request the fake app received */
    protected function requests(): array
    {
        $out = [];
        foreach (@file(self::serverDir() . '/requests.ndjson', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $out[] = json_decode($line, true);
        }

        return $out;
    }

    /** @return list<array<string, mixed>> decoded POST /v1/batch bodies */
    protected function batches(): array
    {
        $out = [];
        foreach ($this->requests() as $r) {
            if ($r['method'] === 'POST' && $r['path'] === '/v1/batch') {
                $out[] = json_decode($r['body'], true);
            }
        }

        return $out;
    }

    /** @return list<array<string, mixed>> entries of all batches, in arrival order */
    protected function entries(): array
    {
        $out = [];
        foreach ($this->batches() as $b) {
            foreach ($b['entries'] as $e) {
                $out[] = $e;
            }
        }

        return $out;
    }

    protected function assertBatchesValid(): void
    {
        $count = 0;
        foreach ($this->requests() as $r) {
            if ($r['method'] === 'POST' && $r['path'] === '/v1/batch') {
                self::assertValidBatchJson($r['body']);
                ++$count;
            }
        }
        $this->assertGreaterThan(0, $count, 'no batch was received');
    }

    public static function assertValidBatchJson(string $json): void
    {
        $validator = self::$validator ??= self::makeValidator();
        $result = $validator->validate(json_decode($json), self::SCHEMA_ID);
        $message = '';
        if (!$result->isValid()) {
            $message = json_encode((new ErrorFormatter())->format($result->error(), true), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                . "\n" . substr($json, 0, 3000);
        }
        self::assertTrue($result->isValid(), 'batch does not match batch.schema.json: ' . $message);
    }

    public static function schemaPath(): string
    {
        return __DIR__ . '/schema/batch.schema.json';
    }

    /**
     * Runs PHP code in a child process (autoloader already required).
     *
     * @param array<string, string> $env
     * @return array{exit: int, out: string, err: string, ms: float}
     */
    protected function runPhp(string $code, array $env = []): array
    {
        $file = self::root() . DIRECTORY_SEPARATOR . 'child-' . bin2hex(random_bytes(4)) . '.php';
        $autoload = var_export(dirname(__DIR__) . '/vendor/autoload.php', true);
        file_put_contents($file, "<?php\nrequire $autoload;\n" . $code);
        $fullEnv = getenv();
        foreach (['GAZE_ENABLED', 'GAZE_HOST', 'GAZE_PORT', 'RAY_HOST', 'RAY_PORT', 'GAZE_TOKEN'] as $k) {
            unset($fullEnv[$k]);
        }
        $proc = proc_open([PHP_BINARY, $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env + $fullEnv);
        $start = microtime(true);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        @unlink($file);

        return ['exit' => $exit, 'out' => (string) $out, 'err' => (string) $err, 'ms' => (microtime(true) - $start) * 1000];
    }

    /** PHP literal that configures Gaze in a child process like setUp() does here. */
    protected function childConfig(int $port = self::PORT, array $extra = []): string
    {
        return '\TheWonderJoy\Gaze\Gaze::configure(' . var_export(['host' => '127.0.0.1', 'port' => $port, 'state_dir' => $this->stateDir, 'logger' => false] + $extra, true) . ");\n";
    }

    private static function makeValidator(): Validator
    {
        $validator = new Validator();
        $validator->setMaxErrors(5);
        $validator->resolver()->registerFile(self::SCHEMA_ID, self::schemaPath());

        return $validator;
    }

    private static function rmrf(string $dir): void
    {
        if ($dir === '' || !is_dir($dir)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
