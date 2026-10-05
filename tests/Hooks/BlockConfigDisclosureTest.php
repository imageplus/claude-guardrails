<?php

declare(strict_types=1);

namespace ImagePlus\ClaudeGuardrails\Tests\Hooks;

use PHPUnit\Framework\Attributes\DataProvider;

final class BlockConfigDisclosureTest extends HookTestCase
{
    protected function hook(): string
    {
        return 'block-config-disclosure.php';
    }

    /** @return array<string,array{string}> */
    public static function blocked(): array
    {
        return [
            'config:show'             => ['php artisan config:show database'],
            'config:show bare'        => ['php artisan config:show'],
            'php -r'                  => ["php -r 'echo file_get_contents(\".env\");'"],
            'php -r attached'         => ["php -r'echo 1;'"],
            'php -a'                  => ['php -a'],
            'versioned binary'        => ["/usr/bin/php8.3 -r 'echo 1;'"],
            'quoted subcommand'       => ["php artisan config:sh''ow"],
            'brace subcommand'        => ['php artisan {config:show,list}'],
            'second command in chain' => ['php artisan test && php artisan config:show app'],
        ];
    }

    #[DataProvider('blocked')]
    public function testBlocks(string $command): void
    {
        self::assertSame(self::BLOCK, $this->bash($command));
    }

    public function testBlocksGlobbedArtisan(): void
    {
        $this->touch('artisan');

        self::assertSame(self::BLOCK, $this->bash('php arti*an config:show database'));
    }

    /** @return array<string,array{string}> */
    public static function allowed(): array
    {
        return [
            'config:cache'          => ['php artisan config:cache'],
            'config:clear'          => ['php artisan config:clear'],
            'about'                 => ['php artisan about'],
            'test'                  => ['php artisan test'],
            'git config'            => ['git config user.name'],
            'npm config'            => ['npm config get registry'],
            'grep -r after php'     => ['php artisan test && grep -r foo .'],
            'grep -r in pipe'       => ['php artisan route:list | grep -r api'],
            'config dir'            => ['ls config/'],
            'php running a script'  => ['php vendor/bin/phpunit'],
            'tinker'                => ['php artisan tinker'],
            'tinker --execute'      => ["php artisan tinker --execute='User::count()'"],
            'tinker on stdin'       => ["echo 'User::count()' | php artisan tinker"],
        ];
    }

    #[DataProvider('allowed')]
    public function testAllows(string $command): void
    {
        self::assertSame(self::ALLOW, $this->bash($command));
    }

    public function testIgnoresNonBashTools(): void
    {
        self::assertSame(self::ALLOW, $this->file('Read', 'config/app.php'));
    }
}
