<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Console;

/**
 * vendor/bin/gaze clean [--dry-run] [path ...]
 *
 * Removes gaze(...) and gaze_dd(...) statements (including chains such as gaze($x)->red()->label('y');)
 * from PHP files, using PHP's own tokenizer. Calls used inside an expression ($y = gaze()->pass($x),
 * if ($a) gaze($b); without braces, <?= gaze() ?>) are reported and left alone, because removing them
 * would change the code around them.
 */
final class CleanCommand
{
    private const HELPERS = ['gaze', 'gaze_dd'];

    private const SKIP_DIRS = ['vendor', 'node_modules', '.git', 'storage', 'cache', '.idea', '.vscode'];

    /** @param list<string> $argv */
    public static function main(array $argv, $out = STDOUT): int
    {
        $args = array_slice($argv, 1);
        $command = array_shift($args);
        if ($command === null || in_array($command, ['-h', '--help', 'help'], true)) {
            fwrite($out, self::usage());

            return $command === null ? 1 : 0;
        }
        if ($command !== 'clean') {
            fwrite($out, "Unknown command \"$command\".\n\n" . self::usage());

            return 1;
        }
        $dry = false;
        $paths = [];
        foreach ($args as $arg) {
            if ($arg === '--dry-run' || $arg === '-n') {
                $dry = true;
            } elseif (str_starts_with($arg, '-')) {
                fwrite($out, "Unknown option \"$arg\".\n\n" . self::usage());

                return 1;
            } else {
                $paths[] = $arg;
            }
        }
        $paths = $paths ?: [(string) getcwd()];

        $removed = $skipped = $files = 0;
        foreach (self::phpFiles($paths) as $file) {
            $code = @file_get_contents($file);
            if ($code === false || stripos($code, 'gaze') === false) {
                continue;
            }
            $result = self::clean($code);
            foreach ($result['removed'] as $r) {
                fwrite($out, sprintf("%s:%d  %s %s\n", $file, $r['line'], $dry ? 'would remove' : 'removed', $r['text']));
            }
            foreach ($result['skipped'] as $s) {
                fwrite($out, sprintf("%s:%d  skipped (%s)\n", $file, $s['line'], $s['reason']));
            }
            $removed += count($result['removed']);
            $skipped += count($result['skipped']);
            if ($result['removed']) {
                ++$files;
                if (!$dry) {
                    file_put_contents($file, $result['code']);
                }
            }
        }
        fwrite($out, sprintf("\n%s %d call%s in %d file%s; %d skipped.\n", $dry ? 'Would remove' : 'Removed', $removed, $removed === 1 ? '' : 's', $files, $files === 1 ? '' : 's', $skipped));

        return 0;
    }

    /**
     * @return array{code: string, removed: list<array{line: int, text: string}>, skipped: list<array{line: int, reason: string}>}
     */
    public static function clean(string $code): array
    {
        try {
            $tokens = \PhpToken::tokenize($code, TOKEN_PARSE);
        } catch (\Throwable) {
            $tokens = \PhpToken::tokenize($code);
        }
        $n = count($tokens);
        $ranges = [];
        $removed = $skipped = [];

        for ($i = 0; $i < $n; ++$i) {
            if (!self::isHelper($tokens[$i])) {
                continue;
            }
            $open = self::next($tokens, $i);
            if ($open === null || $tokens[$open]->text !== '(') {
                continue;
            }
            $prev = self::prev($tokens, $i);
            $before = $prev === null ? null : $tokens[$prev];
            if ($before !== null && (in_array($before->text, ['->', '?->', '::', '&'], true) || $before->is([T_FUNCTION, T_NEW, T_USE, T_CONST, T_FN]))) {
                continue; // a method named gaze(), a declaration, or a reference: not a helper call
            }
            $line = $tokens[$i]->line;
            $atStatementStart = $before === null || in_array($before->text, [';', '{', '}'], true) || $before->is(T_OPEN_TAG);
            if (!$atStatementStart) {
                $skipped[] = ['line' => $line, 'reason' => 'used inside an expression or a statement without braces'];
                continue;
            }
            [$end, $reason] = self::statementEnd($tokens, $open);
            if ($end === null) {
                $skipped[] = ['line' => $line, 'reason' => $reason];
                continue;
            }
            $ranges[] = [$i, $end];
            $text = '';
            for ($k = $i; $k <= $end; ++$k) {
                $text .= $tokens[$k]->text;
            }
            $text = preg_replace('/\s+/', ' ', trim($text)) ?? $text;
            $removed[] = ['line' => $line, 'text' => strlen($text) > 100 ? substr($text, 0, 97) . '...' : $text];
            $i = $end;
        }

        if (!$ranges) {
            return ['code' => $code, 'removed' => [], 'skipped' => $skipped];
        }

        $out = '';
        $cursor = 0;
        foreach ($ranges as [$from, $to]) {
            $start = $tokens[$from]->pos;
            $stop = $tokens[$to]->pos + strlen($tokens[$to]->text);
            // Remove the whole line when the statement is alone on it.
            $lineStart = $start;
            while ($lineStart > $cursor && ($code[$lineStart - 1] === ' ' || $code[$lineStart - 1] === "\t")) {
                --$lineStart;
            }
            $lineEnd = $stop;
            while ($lineEnd < strlen($code) && ($code[$lineEnd] === ' ' || $code[$lineEnd] === "\t")) {
                ++$lineEnd;
            }
            $startsLine = $lineStart === 0 || $code[$lineStart - 1] === "\n";
            $endsLine = $lineEnd >= strlen($code) || $code[$lineEnd] === "\n" || $code[$lineEnd] === "\r";
            if ($startsLine && $endsLine) {
                $start = $lineStart;
                $stop = $lineEnd;
                if (substr($code, $stop, 2) === "\r\n") {
                    $stop += 2;
                } elseif (($code[$stop] ?? '') === "\n") {
                    ++$stop;
                }
            } elseif ($endsLine) {
                $start = $lineStart; // no trailing whitespace left behind
                $stop = $lineEnd;
            }
            $out .= substr($code, $cursor, $start - $cursor);
            $cursor = $stop;
        }
        $out .= substr($code, $cursor);

        return ['code' => $out, 'removed' => $removed, 'skipped' => $skipped];
    }

    private static function isHelper(\PhpToken $t): bool
    {
        if ($t->is(T_STRING)) {
            return in_array(strtolower($t->text), self::HELPERS, true);
        }

        return $t->is(T_NAME_FULLY_QUALIFIED) && in_array(strtolower(ltrim($t->text, '\\')), self::HELPERS, true);
    }

    /**
     * From the opening "(" of the call: the index of the terminating ";" (or the token before "?>"),
     * accepting only a plain chain of method calls after the argument list.
     *
     * @param list<\PhpToken> $tokens
     * @return array{0: int|null, 1: string}
     */
    private static function statementEnd(array $tokens, int $open): array
    {
        $close = self::matching($tokens, $open);
        if ($close === null) {
            return [null, 'unbalanced parentheses'];
        }
        $i = $close;
        while (true) {
            $next = self::next($tokens, $i);
            if ($next === null) {
                return [null, 'no end of statement'];
            }
            $t = $tokens[$next];
            if ($t->text === ';') {
                return [$next, ''];
            }
            if ($t->is(T_CLOSE_TAG)) {
                return [$i, ''];
            }
            if ($t->text === '->' || $t->is(T_NULLSAFE_OBJECT_OPERATOR)) {
                $name = self::next($tokens, $next);
                $paren = $name === null ? null : self::next($tokens, $name);
                if ($name === null || !$tokens[$name]->is(T_STRING) || $paren === null || $tokens[$paren]->text !== '(') {
                    return [null, 'not a plain call chain'];
                }
                $i = self::matching($tokens, $paren);
                if ($i === null) {
                    return [null, 'unbalanced parentheses'];
                }
                continue;
            }

            return [null, 'the call result is used (' . trim($t->text) . ')'];
        }
    }

    /** @param list<\PhpToken> $tokens */
    private static function matching(array $tokens, int $open): ?int
    {
        $depth = 0;
        $n = count($tokens);
        for ($i = $open; $i < $n; ++$i) {
            $text = $tokens[$i]->text;
            if ($text === '(' || $text === '[' || $text === '{' || $tokens[$i]->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                ++$depth;
            } elseif ($text === ')' || $text === ']' || $text === '}') {
                if (--$depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /** @param list<\PhpToken> $tokens */
    private static function next(array $tokens, int $i): ?int
    {
        $n = count($tokens);
        for ($j = $i + 1; $j < $n; ++$j) {
            if (!$tokens[$j]->isIgnorable()) {
                return $j;
            }
        }

        return null;
    }

    /** @param list<\PhpToken> $tokens */
    private static function prev(array $tokens, int $i): ?int
    {
        for ($j = $i - 1; $j >= 0; --$j) {
            if (!$tokens[$j]->isIgnorable() || $tokens[$j]->is(T_OPEN_TAG)) {
                return $j;
            }
        }

        return null;
    }

    /**
     * @param list<string> $paths
     * @return iterable<string>
     */
    private static function phpFiles(array $paths): iterable
    {
        foreach ($paths as $path) {
            if (is_file($path)) {
                yield $path;
                continue;
            }
            if (!is_dir($path)) {
                continue;
            }
            $dirs = new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                static fn (\SplFileInfo $f): bool => !$f->isDir() || !in_array($f->getFilename(), self::SKIP_DIRS, true),
            );
            foreach (new \RecursiveIteratorIterator($dirs) as $file) {
                $name = $file->getFilename();
                if ($file->isFile() && (str_ends_with($name, '.php') || str_ends_with($name, '.phtml'))) {
                    yield $file->getPathname();
                }
            }
        }
    }

    private static function usage(): string
    {
        return <<<'TXT'
            Usage: gaze clean [--dry-run] [path ...]

              Removes gaze(...) and gaze_dd(...) statements, including chained calls, from PHP files.
              Paths default to the current directory; vendor, node_modules, storage, cache and .git are skipped.
              Calls whose result is used are reported and left alone.

              --dry-run, -n   list what would be removed without changing files

            TXT;
    }
}
