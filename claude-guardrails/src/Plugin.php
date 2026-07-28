<?php

declare(strict_types=1);

namespace ImagePlus\ClaudeGuardrails;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;

/**
 * Merges the guardrail deny rules and PreToolUse hooks defined in
 * config/guardrails.json into the host project's .claude/settings.json.
 *
 * Runs automatically after `composer install` and `composer update`.
 * The merge is idempotent and update-safe:
 *   - Deny rules previously added by this package are tracked in a sidecar
 *     (.claude/.guardrails-managed.json) and re-synced each run, so removing a
 *     rule from the package removes it from projects too.
 *   - Hook entries are identified by our package marker in their command path,
 *     stripped, then re-added fresh — so path/version changes stay clean.
 * Anything the project added itself (its own allow rules, other hooks) is left
 * untouched.
 */
final class Plugin implements PluginInterface, EventSubscriberInterface
{
    private const PACKAGE_NAME = 'imageplus/claude-guardrails';
    private const MARKER       = 'imageplus/claude-guardrails';

    private Composer $composer;
    private IOInterface $io;

    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->composer = $composer;
        $this->io       = $io;
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
    }

    /** @return array<string, string> */
    public static function getSubscribedEvents(): array
    {
        return [
            ScriptEvents::POST_INSTALL_CMD => 'sync',
            ScriptEvents::POST_UPDATE_CMD  => 'sync',
        ];
    }

    public function sync(Event $event): void
    {
        try {
            $this->run();
        } catch (\Throwable $e) {
            // Never break the host project's install/update over a sync failure.
            $this->io->writeError('<warning>[claude-guardrails] Skipped settings sync: ' . $e->getMessage() . '</warning>');
        }
    }

    private function run(): void
    {
        // Don't run against our own package repository.
        if ($this->composer->getPackage()->getName() === self::PACKAGE_NAME) {
            return;
        }

        $vendorDir   = (string) $this->composer->getConfig()->get('vendor-dir');
        $projectRoot = \dirname($vendorDir);
        $packageDir  = $this->resolvePackageDir($vendorDir);
        $relPackage  = $this->relativePath($projectRoot, $packageDir);

        $config = $this->readJson($packageDir . '/config/guardrails.json');
        if ($config === null) {
            $this->io->writeError('<warning>[claude-guardrails] config/guardrails.json missing; nothing to sync.</warning>');
            return;
        }

        $claudeDir    = $projectRoot . '/.claude';
        $settingsPath = $claudeDir . '/settings.json';
        $sidecarPath  = $claudeDir . '/.guardrails-managed.json';

        if (!is_dir($claudeDir) && !mkdir($claudeDir, 0755, true) && !is_dir($claudeDir)) {
            throw new \RuntimeException('Could not create ' . $claudeDir);
        }

        $settings = $this->readJson($settingsPath) ?? [];
        $managed  = $this->readJson($sidecarPath) ?? ['deny' => []];

        // ---- Desired values from the package config ----
        $desiredDeny = array_map(
            static fn (string $rule): string => str_replace('__PACKAGE_PATH__', $relPackage, $rule),
            $config['deny'] ?? []
        );

        $desiredHookGroups = [];
        foreach (($config['hooks'] ?? []) as $hook) {
            $desiredHookGroups[] = [
                'matcher' => $hook['matcher'],
                'hooks'   => [[
                    'type'    => 'command',
                    'command' => 'php $CLAUDE_PROJECT_DIR/' . $relPackage . '/hooks/' . $hook['script'],
                ]],
            ];
        }

        // ---- Merge deny rules ----
        $settings['permissions'] ??= [];
        $settings['permissions']['deny'] ??= [];

        $prevManagedDeny = $managed['deny'] ?? [];
        $settings['permissions']['deny'] = array_values(array_filter(
            $settings['permissions']['deny'],
            static fn (string $rule): bool => !\in_array($rule, $prevManagedDeny, true)
        ));
        foreach ($desiredDeny as $rule) {
            if (!\in_array($rule, $settings['permissions']['deny'], true)) {
                $settings['permissions']['deny'][] = $rule;
            }
        }

        // ---- Merge hooks ----
        $settings['hooks'] ??= [];
        $settings['hooks']['PreToolUse'] ??= [];
        $settings['hooks']['PreToolUse'] = $this->stripOurHooks($settings['hooks']['PreToolUse']);
        foreach ($desiredHookGroups as $group) {
            $settings['hooks']['PreToolUse'][] = $group;
        }
        $settings['hooks']['PreToolUse'] = array_values($settings['hooks']['PreToolUse']);

        // ---- Persist ----
        $this->writeJson($settingsPath, $settings);
        $this->writeJson($sidecarPath, ['deny' => $desiredDeny]);

        $this->io->write('<info>[claude-guardrails] Synced deny rules and PreToolUse hooks into .claude/settings.json</info>');
    }

    /**
     * Remove any PreToolUse hook entry that belongs to this package (identified
     * by our marker in the command), dropping groups that become empty.
     *
     * @param array<int, array<string, mixed>> $groups
     * @return array<int, array<string, mixed>>
     */
    private function stripOurHooks(array $groups): array
    {
        $out = [];
        foreach ($groups as $group) {
            if (!isset($group['hooks']) || !\is_array($group['hooks'])) {
                $out[] = $group;
                continue;
            }
            $group['hooks'] = array_values(array_filter(
                $group['hooks'],
                static fn ($h): bool => !isset($h['command']) || !str_contains((string) $h['command'], self::MARKER)
            ));
            if ($group['hooks'] !== []) {
                $out[] = $group;
            }
        }
        return $out;
    }

    private function resolvePackageDir(string $vendorDir): string
    {
        $repo = $this->composer->getRepositoryManager()->getLocalRepository();
        $pkg  = $repo->findPackage(self::PACKAGE_NAME, '*');
        if ($pkg !== null) {
            $path = $this->composer->getInstallationManager()->getInstallPath($pkg);
            if (\is_string($path) && $path !== '') {
                return $path;
            }
        }
        return $vendorDir . '/' . self::PACKAGE_NAME;
    }

    private function relativePath(string $from, string $to): string
    {
        $from = rtrim(str_replace('\\', '/', $from), '/');
        $to   = rtrim(str_replace('\\', '/', $to), '/');

        if (str_starts_with($to . '/', $from . '/')) {
            return ltrim(substr($to, \strlen($from)), '/');
        }

        // Fallback to the conventional vendor location.
        return 'vendor/' . self::PACKAGE_NAME;
    }

    /** @return array<mixed>|null */
    private function readJson(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return null;
        }
        $data = json_decode($raw, true);

        return \is_array($data) ? $data : null;
    }

    /** @param array<mixed> $data */
    private function writeJson(string $path, array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode JSON for ' . $path);
        }
        if (file_put_contents($path, $json . PHP_EOL) === false) {
            throw new \RuntimeException('Failed to write ' . $path);
        }
    }
}
