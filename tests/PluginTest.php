<?php

declare(strict_types=1);

namespace ImagePlus\ClaudeGuardrails\Tests;

use Composer\Composer;
use Composer\Config;
use Composer\Installer\InstallationManager;
use Composer\IO\BufferIO;
use Composer\Package\RootPackage;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Repository\RepositoryManager;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use ImagePlus\ClaudeGuardrails\Plugin;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the settings.json merge against a throwaway host project whose
 * vendor/imageplus/claude-guardrails is a symlink, as Composer installs it.
 */
final class PluginTest extends TestCase
{
    private const VENDOR_PATH = 'vendor/imageplus/claude-guardrails';

    private string $project;

    protected function setUp(): void
    {
        $this->project = realpath(sys_get_temp_dir()) . '/guardrails-plugin-' . bin2hex(random_bytes(6));
        mkdir($this->project . '/vendor/imageplus', 0755, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->project));
    }

    /** Package lives outside the project (normal VCS install, real dir in vendor/ simulated by a symlink out). */
    private function installFromOutside(): void
    {
        symlink(\dirname(__DIR__), $this->project . '/' . self::VENDOR_PATH);
    }

    /** Package installed from a Composer path repository inside the project. */
    private function installFromPathRepo(): void
    {
        mkdir($this->project . '/packages/claude-guardrails/config', 0755, true);
        copy(\dirname(__DIR__) . '/config/guardrails.json', $this->project . '/packages/claude-guardrails/config/guardrails.json');
        symlink('../../packages/claude-guardrails', $this->project . '/' . self::VENDOR_PATH);
    }

    private function sync(string $rootName = 'acme/app'): BufferIO
    {
        $config = new Config(false, $this->project);
        $config->merge(['config' => ['vendor-dir' => $this->project . '/vendor']]);

        $localRepo = $this->createMock(InstalledRepositoryInterface::class);
        $localRepo->method('findPackage')->willReturn(null);
        $repoManager = $this->createMock(RepositoryManager::class);
        $repoManager->method('getLocalRepository')->willReturn($localRepo);

        $composer = new Composer();
        $composer->setPackage(new RootPackage($rootName, '1.0.0.0', '1.0.0'));
        $composer->setConfig($config);
        $composer->setRepositoryManager($repoManager);
        $composer->setInstallationManager($this->createMock(InstallationManager::class));

        $io     = new BufferIO();
        $plugin = new Plugin();
        $plugin->activate($composer, $io);
        $plugin->sync(new Event(ScriptEvents::POST_INSTALL_CMD, $composer, $io));

        return $io;
    }

    /** @return array<mixed> */
    private function settings(): array
    {
        return json_decode((string) file_get_contents($this->project . '/.claude/settings.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    private function writeSettings(array $settings): void
    {
        if (!is_dir($this->project . '/.claude')) {
            mkdir($this->project . '/.claude');
        }
        file_put_contents($this->project . '/.claude/settings.json', json_encode($settings));
    }

    /** @return array<int,string> */
    private function hookCommands(): array
    {
        $out = [];
        foreach ($this->settings()['hooks']['PreToolUse'] as $group) {
            foreach ($group['hooks'] as $hook) {
                $out[] = $hook['command'];
            }
        }
        return $out;
    }

    public function testCreatesSettingsWithDenyRulesAndHooks(): void
    {
        $this->installFromOutside();
        $io = $this->sync();

        $deny = $this->settings()['permissions']['deny'];
        self::assertContains('Bash(*vapor*)', $deny);
        self::assertContains('Bash(aws:*)', $deny);
        self::assertContains('Read(**/.env)', $deny);
        self::assertContains('Edit(' . self::VENDOR_PATH . '/**)', $deny);
        self::assertContains('Write(' . self::VENDOR_PATH . '/**)', $deny);
        self::assertNotContains('Edit(__PACKAGE_PATH__/**)', $deny);

        self::assertSame([
            'php $CLAUDE_PROJECT_DIR/' . self::VENDOR_PATH . '/hooks/block-vapor.php',
            'php $CLAUDE_PROJECT_DIR/' . self::VENDOR_PATH . '/hooks/block-aws.php',
            'php $CLAUDE_PROJECT_DIR/' . self::VENDOR_PATH . '/hooks/block-config-disclosure.php',
            'php $CLAUDE_PROJECT_DIR/' . self::VENDOR_PATH . '/hooks/protected-files.php',
        ], $this->hookCommands());

        self::assertFileExists($this->project . '/.claude/.guardrails-managed.json');
        self::assertStringContainsString('Synced deny rules', $io->getOutput());
    }

    public function testEveryConfiguredHookScriptExists(): void
    {
        $config = json_decode((string) file_get_contents(\dirname(__DIR__) . '/config/guardrails.json'), true);

        foreach ($config['hooks'] as $hook) {
            // A missing script fails *open* in Claude Code, so this must never drift.
            self::assertFileExists(\dirname(__DIR__) . '/hooks/' . $hook['script']);
        }
    }

    public function testPathRepositoryProtectsBothSpellings(): void
    {
        $this->installFromPathRepo();
        $this->sync();

        $deny = $this->settings()['permissions']['deny'];
        self::assertContains('Edit(' . self::VENDOR_PATH . '/**)', $deny);
        self::assertContains('Edit(packages/claude-guardrails/**)', $deny);
        self::assertContains('Write(packages/claude-guardrails/**)', $deny);
    }

    public function testPreservesProjectOwnRulesAndHooks(): void
    {
        $this->installFromOutside();
        $this->writeSettings([
            'permissions' => ['allow' => ['Bash(npm test)'], 'deny' => ['Bash(rm -rf:*)']],
            'hooks'       => ['PreToolUse' => [['matcher' => 'Bash', 'hooks' => [['type' => 'command', 'command' => 'echo mine']]]]],
            'model'       => 'opus',
        ]);

        $this->sync();
        $settings = $this->settings();

        self::assertSame(['Bash(npm test)'], $settings['permissions']['allow']);
        self::assertContains('Bash(rm -rf:*)', $settings['permissions']['deny']);
        self::assertContains('echo mine', $this->hookCommands());
        self::assertSame('opus', $settings['model']);
    }

    public function testResyncIsIdempotent(): void
    {
        $this->installFromOutside();
        $this->sync();
        $first = $this->settings();

        $this->sync();

        self::assertSame($first, $this->settings());
    }

    public function testResyncDropsRulesThePackageNoLongerShips(): void
    {
        $this->installFromOutside();
        $this->writeSettings(['permissions' => ['deny' => ['Bash(old-rule)', 'Bash(project-rule)']]]);
        file_put_contents($this->project . '/.claude/.guardrails-managed.json', json_encode(['deny' => ['Bash(old-rule)']]));

        $this->sync();
        $deny = $this->settings()['permissions']['deny'];

        self::assertNotContains('Bash(old-rule)', $deny);
        self::assertContains('Bash(project-rule)', $deny);
    }

    public function testResyncReplacesStaleHookPaths(): void
    {
        $this->installFromOutside();
        $this->writeSettings(['hooks' => ['PreToolUse' => [[
            'matcher' => 'Bash',
            'hooks'   => [['type' => 'command', 'command' => 'php old/path/imageplus/claude-guardrails/hooks/gone.php']],
        ]]]]);

        $this->sync();

        self::assertCount(4, $this->hookCommands());
        self::assertNotContains('php old/path/imageplus/claude-guardrails/hooks/gone.php', $this->hookCommands());
    }

    public function testDoesNothingInItsOwnRepository(): void
    {
        $this->installFromOutside();
        $this->sync('imageplus/claude-guardrails');

        self::assertDirectoryDoesNotExist($this->project . '/.claude');
    }

    public function testFailureWarnsInsteadOfBreakingTheInstall(): void
    {
        $this->installFromOutside();
        $this->writeSettings([]);
        chmod($this->project . '/.claude/settings.json', 0444);

        // The plugin's failed write raises a PHP warning before it throws; that
        // warning is the scenario under test, not a test problem.
        set_error_handler(static fn (): bool => true, E_WARNING);
        try {
            $io = $this->sync();
        } finally {
            restore_error_handler();
            chmod($this->project . '/.claude/settings.json', 0644);
        }

        self::assertStringContainsString('Skipped settings sync', $io->getOutput());
    }
}
