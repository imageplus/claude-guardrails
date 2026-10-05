<?php

declare(strict_types=1);

namespace ImagePlus\ClaudeGuardrails\Tests\Hooks;

use PHPUnit\Framework\Attributes\DataProvider;

final class BlockVaporTest extends HookTestCase
{
    protected function hook(): string
    {
        return 'block-vapor.php';
    }

    /** @return array<string,array{string}> */
    public static function blocked(): array
    {
        return [
            'bare'               => ['vapor deploy production'],
            'vendor bin'         => ['./vendor/bin/vapor deploy production'],
            'via php'            => ['php vendor/bin/vapor env:pull production'],
            'namespaced command' => ['vapor:deploy'],
            'chained'            => ['composer install && vapor deploy staging'],
            'single quotes'      => ["va''por deploy"],
            'double quotes'      => ['va"po"r deploy'],
            'backslash'          => ['va\\por deploy'],
            'brace alternation'  => ['vap{or,e} deploy'],
            'upper case'         => ['VAPOR deploy'],
        ];
    }

    #[DataProvider('blocked')]
    public function testBlocks(string $command): void
    {
        self::assertSame(self::BLOCK, $this->bash($command));
    }

    public function testBlocksGlobThatResolvesToTheVaporBinary(): void
    {
        $this->touch('vendor/bin/vapor');

        self::assertSame(self::BLOCK, $this->bash('./vendor/bin/vap* deploy'));
        self::assertSame(self::BLOCK, $this->bash('./vendor/bin/vap?r deploy'));
    }

    /** @return array<string,array{string}> */
    public static function allowed(): array
    {
        return [
            'artisan'          => ['php artisan migrate'],
            'word inside word' => ['grep -r vaporware .'],
            'composer'         => ['composer install'],
        ];
    }

    #[DataProvider('allowed')]
    public function testAllows(string $command): void
    {
        self::assertSame(self::ALLOW, $this->bash($command));
    }

    public function testIgnoresNonBashTools(): void
    {
        self::assertSame(self::ALLOW, $this->file('Read', 'vapor.yml'));
    }

    public function testExplainsWhyOnStderr(): void
    {
        [, $stderr] = $this->runHook(['tool_name' => 'Bash', 'tool_input' => ['command' => 'vapor deploy']]);

        self::assertStringContainsString('Vapor commands must be run manually', $stderr);
    }
}
