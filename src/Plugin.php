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

        // Hook commands must use the install path: stripOurHooks() identifies our
        // hooks by the package name inside it, so this spelling has to be stable.
        $relPackage = $this->relativePath($projectRoot, $packageDir);

        // Deny rules must cover *every* in-project path that reaches the package.
        // With a Composer path repository the install path is a symlink, so
        // protecting only that leaves the real directory writable.
        $packagePaths = $this->packageRelativePaths($projectRoot, $packageDir);

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
        // A rule using __PACKAGE_PATH__ becomes one rule per path that reaches
        // the package (install path and, when it is a symlink, its real target).
        $desiredDeny = [];
        foreach (($config['deny'] ?? []) as $rule) {
            if (!\is_string($rule)) {
                continue;
            }
            $expansions = str_contains($rule, '__PACKAGE_PATH__')
                ? array_map(
                    static fn (string $path): string => str_replace('__PACKAGE_PATH__', $path, $rule),
                    $packagePaths
                )
                : [$rule];

            foreach ($expansions as $expanded) {
                if (!\in_array($expanded, $desiredDeny, true)) {
                    $desiredDeny[] = $expanded;
                }
            }
        }

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

    /**
     * Every project-relative path that reaches the package directory.
     *
     * Normally one: `vendor/imageplus/claude-guardrails`. But when the package
     * is installed from a Composer *path* repository, that is a symlink to a
     * real directory elsewhere in the project — and a deny rule on the symlink
     * does not protect the target, so the package's own files stay editable via
     * their real path. Both spellings are returned so both get denied.
     *
     * Targets outside the project root are skipped: permission globs are
     * project-relative and cannot express them.
     *
     * @return array<int,string>
     */
    private function packageRelativePaths(string $projectRoot, string $packageDir): array
    {
        $roots = array_values(array_unique(array_filter([
            $projectRoot,
            realpath($projectRoot) ?: null,
        ])));

        $targets = [$packageDir];
        $real    = realpath($packageDir);
        if (\is_string($real) && $real !== '' && !\in_array($real, $targets, true)) {
            $targets[] = $real;
        }

        $paths = [];
        foreach ($targets as $target) {
            foreach ($roots as $root) {
                $rel = $this->relativeIfInside($root, $target);
                if ($rel !== null) {
                    if (!\in_array($rel, $paths, true)) {
                        $paths[] = $rel;
                    }
                    break;
                }
            }
        }

        if ($paths === []) {
            $paths[] = 'vendor/' . self::PACKAGE_NAME;
        }

        return $paths;
    }

    /** Project-relative path, or null when $to lies outside $from. */
    private function relativeIfInside(string $from, string $to): ?string
    {
        $from = rtrim(str_replace('\\', '/', $from), '/');
        $to   = rtrim(str_replace('\\', '/', $to), '/');

        if ($from !== '' && str_starts_with($to . '/', $from . '/')) {
            $rel = ltrim(substr($to, \strlen($from)), '/');

            return $rel === '' ? null : $rel;
        }

        return null;
    }

    private function relativePath(string $from, string $to): string
    {
        // Fallback to the conventional vendor location.
        return $this->relativeIfInside($from, $to) ?? 'vendor/' . self::PACKAGE_NAME;
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
