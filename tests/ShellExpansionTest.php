<?php

declare(strict_types=1);

namespace ImagePlus\ClaudeGuardrails\Tests;

use ImagePlus\ClaudeGuardrails\ShellExpansion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ShellExpansionTest extends TestCase
{
    /** @return array<int,string> */
    private static function words(string $cmd): array
    {
        return array_column(ShellExpansion::tokenize($cmd), 'text');
    }

    public function testTokenizeRemovesQuotesAndEscapes(): void
    {
        self::assertSame(['cat', '.env'], self::words("cat '.e'nv"));
        self::assertSame(['cat', '.env'], self::words('cat ".e"nv'));
        self::assertSame(['cat', '.env'], self::words('cat .e\\nv'));
        self::assertSame(['echo', 'a b'], self::words('echo "a b"'));
    }

    public function testTokenizeSplitsOnOperators(): void
    {
        self::assertSame(['a', 'b', 'c', 'd'], self::words('a|b && c;d'));
        self::assertSame(['echo', 'x', 'out'], self::words('echo x > out'));
    }

    public function testTokenizeFlagsDynamicWords(): void
    {
        $tokens = ShellExpansion::tokenize('cat $f "x$y" plain `cmd`');

        self::assertSame([false, true, true, false, true], array_column($tokens, 'dynamic'));
    }

    public function testTokenizeKeepsDollarLiteralInSingleQuotes(): void
    {
        self::assertSame([['text' => '$f', 'dynamic' => false]], ShellExpansion::tokenize("'\$f'"));
    }

    /** @return array<string,array{string,array<int,string>}> */
    public static function splits(): array
    {
        return [
            'and'               => ['a && b', ['a', 'b']],
            'or'                => ['a || b', ['a', 'b']],
            'pipe'              => ['a | b', ['a', 'b']],
            'semicolon'         => ['a; b', ['a', 'b']],
            'newline'           => ["a\nb", ['a', 'b']],
            'quoted separator'  => ["echo 'a && b'", ["echo 'a && b'"]],
            'dquoted separator' => ['echo "a; b"', ['echo "a; b"']],
            'escaped separator' => ['echo a\\;b', ['echo a\\;b']],
            'empty segments'    => [';; a ;', ['a']],
        ];
    }

    /** @param array<int,string> $expected */
    #[DataProvider('splits')]
    public function testSplitCommands(string $cmd, array $expected): void
    {
        self::assertSame($expected, ShellExpansion::splitCommands($cmd));
    }

    public function testBraceExpand(): void
    {
        self::assertSame(['.env', '.exv'], ShellExpansion::braceExpand('.e{n,x}v'));
        self::assertSame(['ac', 'ad', 'bc', 'bd'], ShellExpansion::braceExpand('{a,b}{c,d}'));
        self::assertSame(['a1', 'a2', 'b'], ShellExpansion::braceExpand('{a{1,2},b}'));
    }

    public function testBraceExpandLeavesNonAlternationsAlone(): void
    {
        self::assertSame(['plain'], ShellExpansion::braceExpand('plain'));
        self::assertSame(['{x}'], ShellExpansion::braceExpand('{x}'));
        self::assertSame(['a{b'], ShellExpansion::braceExpand('a{b'));
    }

    public function testGlobResolvesAgainstProjectDir(): void
    {
        $dir = sys_get_temp_dir() . '/guardrails-glob-' . bin2hex(random_bytes(6));
        mkdir($dir);
        touch($dir . '/.env');
        $previous = getenv('CLAUDE_PROJECT_DIR');
        putenv('CLAUDE_PROJECT_DIR=' . $dir);

        try {
            self::assertSame([$dir . '/.env'], ShellExpansion::glob('.e?v'));
            self::assertSame([], ShellExpansion::glob('.env'), 'no metacharacters, nothing to expand');
            self::assertSame([], ShellExpansion::glob('missing*'));
        } finally {
            putenv($previous === false ? 'CLAUDE_PROJECT_DIR' : 'CLAUDE_PROJECT_DIR=' . $previous);
            unlink($dir . '/.env');
            rmdir($dir);
        }
    }
}
