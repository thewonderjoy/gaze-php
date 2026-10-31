<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Tests\Unit;

use TheWonderJoy\Gaze\Console\CleanCommand;
use TheWonderJoy\Gaze\Tests\TestCase;

final class CleanCommandTest extends TestCase
{
    public function testRemovesStatementsAndChainsButKeepsExpressions(): void
    {
        $code = <<<'PHP'
            <?php
            namespace App;

            class OrderController
            {
                public function show($order)
                {
                    gaze($order);
                    \gaze('fqn')->red()->label('x');
                    gaze()->table(['a' => 1])
                        ->large();
                    $total = gaze()->pass($order->total);
                    if ($order) gaze('no braces');
                    if ($order) { gaze_dd($order); }
                    $this->gaze('a method named gaze');
                    echo "gaze('in a string')"; // gaze('in a comment');
                    gaze($order) || throw new \Exception();
                    return $total; gaze('after return on the same line');
                }

                public function gaze($x) {}
            }
            PHP;
        $result = CleanCommand::clean($code);

        $this->assertSame([8, 9, 10, 14, 18], array_column($result['removed'], 'line'));
        $this->assertSame([12, 13, 17], array_column($result['skipped'], 'line'));
        $this->assertSame("gaze()->table(['a' => 1]) ->large();", $result['removed'][2]['text']);

        $expected = <<<'PHP'
            <?php
            namespace App;

            class OrderController
            {
                public function show($order)
                {
                    $total = gaze()->pass($order->total);
                    if ($order) gaze('no braces');
                    if ($order) {  }
                    $this->gaze('a method named gaze');
                    echo "gaze('in a string')"; // gaze('in a comment');
                    gaze($order) || throw new \Exception();
                    return $total;
                }

                public function gaze($x) {}
            }
            PHP;
        $this->assertSame($expected, $result['code']);
        $this->assertNotFalse(@\PhpToken::tokenize($result['code'], TOKEN_PARSE));
    }

    public function testTemplatesAndCrlf(): void
    {
        $result = CleanCommand::clean("<p><?php gaze(\$x) ?></p>\r\n<?= gaze()->pass(\$y) ?>\r\n<?php\r\n  gaze_dd(1);\r\necho 2;\r\n");
        $this->assertSame("<p><?php  ?></p>\r\n<?= gaze()->pass(\$y) ?>\r\n<?php\r\necho 2;\r\n", $result['code']);
        $this->assertCount(2, $result['removed']);
        $this->assertCount(1, $result['skipped']);
    }

    public function testCommandLineDryRunAndRewrite(): void
    {
        $dir = $this->stateDir . DIRECTORY_SEPARATOR . 'app';
        @mkdir($dir . '/vendor/x', 0777, true);
        file_put_contents($dir . '/a.php', "<?php\ngaze('debug');\necho 1;\n");
        file_put_contents($dir . '/vendor/x/b.php', "<?php\ngaze('vendor code stays');\n");

        $out = fopen('php://memory', 'w+');
        $this->assertSame(0, CleanCommand::main(['gaze', 'clean', '--dry-run', $dir], $out));
        rewind($out);
        $this->assertStringContainsString('would remove', (string) stream_get_contents($out));
        $this->assertSame("<?php\ngaze('debug');\necho 1;\n", file_get_contents($dir . '/a.php'));

        $r = $this->runPhp('exit(\TheWonderJoy\Gaze\Console\CleanCommand::main(["gaze", "clean", ' . var_export($dir, true) . ']));');
        $this->assertSame(0, $r['exit'], $r['err']);
        $this->assertStringContainsString('Removed 1 call in 1 file', $r['out']);
        $this->assertSame("<?php\necho 1;\n", file_get_contents($dir . '/a.php'));
        $this->assertSame("<?php\ngaze('vendor code stays');\n", file_get_contents($dir . '/vendor/x/b.php'));

        $bin = $this->runPhp('passthru(escapeshellarg(PHP_BINARY) . " " . escapeshellarg(' . var_export(dirname(__DIR__, 2) . '/bin/gaze', true) . ') . " --help", $code); exit($code);');
        $this->assertSame(0, $bin['exit']);
        $this->assertStringContainsString('Usage: gaze clean', $bin['out']);
    }
}
