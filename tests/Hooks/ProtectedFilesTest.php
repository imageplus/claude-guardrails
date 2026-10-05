<?php

declare(strict_types=1);

namespace ImagePlus\ClaudeGuardrails\Tests\Hooks;

use PHPUnit\Framework\Attributes\DataProvider;

final class ProtectedFilesTest extends HookTestCase
{
    protected function hook(): string
    {
        return 'protected-files.php';
    }

    /** @return array<string,array{string}> */
    public static function protectedPaths(): array
    {
        return [
            '.env'                  => ['.env'],
            'nested .env'           => ['packages/api/.env'],
            '.env.production'       => ['.env.production'],
            'auth.json'             => ['auth.json'],
            'secrets dir'           => ['secrets/stripe.txt'],
            'pem'                   => ['certs/server.pem'],
            'key'                   => ['storage/oauth-private.key'],
            'p8'                    => ['AuthKey_ABC123.p8'],
            'keystore'              => ['android/app/release.keystore'],
            'wp-config'             => ['wp-config.php'],
            'nested wp-config'      => ['public/wp-config.php'],
            'wp-config variant'     => ['wp-config-dev.php'],
            'wp-salt'               => ['wp-salt.php'],
            '.htpasswd'             => ['public/.htpasswd'],
            'secrets.properties'    => ['android/secrets.properties'],
            'cached config'         => ['bootstrap/cache/config.php'],
        ];
    }

    #[DataProvider('protectedPaths')]
    public function testFileToolsAreBlocked(string $path): void
    {
        foreach (['Read', 'Edit', 'Write'] as $tool) {
            self::assertSame(self::BLOCK, $this->file($tool, $path), "$tool $path");
        }
    }

    public function testAbsolutePathInsideProjectIsBlocked(): void
    {
        self::assertSame(self::BLOCK, $this->file('Read', $this->project . '/.env'));
    }

    /** @return array<string,array{string}> */
    public static function readablePaths(): array
    {
        return [
            '.env.example'          => ['.env.example'],
            '.env.sample'           => ['.env.sample'],
            '.env.dist'             => ['.env.dist'],
            '.env.template'         => ['.env.template'],
            'wp-config-sample'      => ['wp-config-sample.php'],
            'gradle.properties'     => ['android/gradle.properties'],
            'config file'           => ['config/database.php'],
            'other bootstrap cache' => ['bootstrap/cache/packages.php'],
        ];
    }

    #[DataProvider('readablePaths')]
    public function testFileToolsAreAllowed(string $path): void
    {
        self::assertSame(self::ALLOW, $this->file('Read', $path));
    }

    /** @return array<string,array{string}> */
    public static function blockedCommands(): array
    {
        return [
            'cat'            => ['cat .env'],
            'source'         => ['source .env'],
            'cp source'      => ['cp .env /tmp/x'],
            'redirect'       => ['echo FOO=1 >> .env'],
            'quoted'         => ["cat '.e'nv"],
            'escaped'        => ['cat .e\\nv'],
            'brace'          => ['cat .e{n,x}v'],
            'wp-config'      => ['grep DB_PASSWORD wp-config.php'],
            'secrets dir'    => ['cat sec\\rets/x'],
            'piped'          => ['cat .env | grep KEY'],
        ];
    }

    #[DataProvider('blockedCommands')]
    public function testBashIsBlocked(string $command): void
    {
        self::assertSame(self::BLOCK, $this->bash($command));
    }

    public function testBashGlobResolvingToProtectedFileIsBlocked(): void
    {
        $this->touch('.env');
        $this->touch('store/server.pem');

        self::assertSame(self::BLOCK, $this->bash('cat .???'));
        self::assertSame(self::BLOCK, $this->bash('cat store/*.pem'));
    }

    /** @return array<string,array{string}> */
    public static function allowedCommands(): array
    {
        return [
            'env template' => ['cat .env.example'],
            'cp template'  => ['cp .env.example /tmp/env.example'],
            'artisan'      => ['php artisan migrate'],
            'ls'           => ['ls -la'],
        ];
    }

    #[DataProvider('allowedCommands')]
    public function testBashIsAllowed(string $command): void
    {
        self::assertSame(self::ALLOW, $this->bash($command));
    }

    public function testBlockMessageNamesTheFile(): void
    {
        [, $stderr] = $this->runHook(['tool_name' => 'Read', 'tool_input' => ['file_path' => 'config/.env']]);

        self::assertStringContainsString("'.env' is a protected/secret file", $stderr);
    }

    public function testEmptyOrMalformedInputIsAllowed(): void
    {
        self::assertSame(self::ALLOW, $this->runHook([])[0]);
        self::assertSame(self::ALLOW, $this->file('Read', ''));
    }
}
