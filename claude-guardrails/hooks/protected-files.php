#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * PreToolUse hook: stop Claude reading or touching protected/secret files.
 *
 * Generalises the old .env-only hook into a configurable one. It covers the
 * built-in Read/Edit/Write tools and Bash commands (cat, less, source,
 * redirects, cp targets, etc.).
 *
 * Protected patterns are loaded from config/guardrails.json:
 *   - every `Read(<glob>)` entry in the "deny" list (single source of truth:
 *     whatever you deny at the permission layer is hardened for Bash too), plus
 *   - any extra globs in "protectedFiles.deny".
 * Exceptions (template files that must stay readable) come from
 * "protectedFiles.allow". A small set of .env defaults is always applied so
 * protection never silently disappears if the config is missing or malformed.
 *
 * The Bash path does NOT rely on the literal filename appearing in the command.
 * It uses the shared ShellExpansion helpers to tokenise, brace-expand and glob
 * the command the way a shell would, so runtime-computed spellings such as
 * `.???`, `*.k''ey`, `sec\rets/x` and `store/*.pem` resolve to their real
 * target before the check.
 *
 * Residual limitation: filenames produced purely by variable expansion (`$f`)
 * or command substitution (`$(...)`) cannot be resolved statically. Treat this
 * hook as defence-in-depth; the real control is filesystem permissions.
 */

require_once __DIR__ . '/../src/ShellExpansion.php';

use ImagePlus\ClaudeGuardrails\ShellExpansion;

$raw  = stream_get_contents(STDIN) ?: '';
$data = json_decode($raw, true);
$data = is_array($data) ? $data : [];

$tool = $data['tool_name'] ?? '';

/** Project root used to derive project-relative path forms. */
function guardrails_project_root(): string
{
    return rtrim((string) (getenv('CLAUDE_PROJECT_DIR') ?: getcwd() ?: ''), '/');
}

/** Convert a gitignore/Claude-style glob into an anchored regex. */
function guardrails_glob_to_regex(string $glob): string
{
    $glob = trim($glob);
    if (str_starts_with($glob, './')) {
        $glob = substr($glob, 2);
    }
    $glob = ltrim($glob, '/');

    $re = '';
    $len = strlen($glob);
    for ($i = 0; $i < $len; $i++) {
        $c = $glob[$i];
        if ($c === '*') {
            if ($i + 1 < $len && $glob[$i + 1] === '*') {          // **
                if ($i + 2 < $len && $glob[$i + 2] === '/') {      // **/  -> optional dirs
                    $re .= '(?:.*/)?';
                    $i += 2;
                } else {
                    $re .= '.*';
                    $i += 1;
                }
            } else {
                $re .= '[^/]*';                                     // *  -> within a segment
            }
        } elseif ($c === '?') {
            $re .= '[^/]';
        } elseif ($c === '[') {                                     // char class
            $j = $i + 1;
            $neg = false;
            if ($j < $len && ($glob[$j] === '!' || $glob[$j] === '^')) {
                $neg = true;
                $j++;
            }
            $class = '';
            while ($j < $len && $glob[$j] !== ']') {
                $class .= $glob[$j];
                $j++;
            }
            if ($j >= $len) {
                $re .= '\\[';                                       // unterminated -> literal
            } else {
                $re .= '[' . ($neg ? '^' : '') . $class . ']';
                $i = $j;
            }
        } else {
            $re .= preg_quote($c, '#');
        }
    }

    return '#^' . $re . '$#';
}

/**
 * Normalised forms of a candidate path to test against patterns: as-is,
 * without a leading ./, the basename, and the project-relative path, each
 * additionally with any leading slash stripped.
 *
 * @return array<int,string>
 */
function guardrails_forms(string $path): array
{
    $path = trim($path);
    if ($path === '') {
        return [];
    }

    $forms = [$path, basename($path)];
    if (str_starts_with($path, './')) {
        $forms[] = substr($path, 2);
    }
    $root = guardrails_project_root();
    if ($root !== '' && str_starts_with($path, $root . '/')) {
        $forms[] = substr($path, strlen($root) + 1);
    }

    foreach ($forms as $f) {
        $forms[] = ltrim($f, '/');
    }

    return array_values(array_unique(array_filter($forms, static fn (string $f): bool => $f !== '')));
}

/**
 * Load protected/allowed glob patterns from config/guardrails.json, compiled
 * to regexes. Always includes .env defaults so protection can't silently
 * vanish. Returns ['deny' => string[], 'allow' => string[]].
 *
 * @return array{deny:array<int,string>,allow:array<int,string>}
 */
function guardrails_load_patterns(): array
{
    $denyGlobs  = ['**/.env', '**/.env.*'];
    $allowGlobs = ['**/.env.example', '**/.env.sample', '**/.env.dist', '**/.env.template'];

    $configPath = __DIR__ . '/../config/guardrails.json';
    if (is_file($configPath)) {
        $config = json_decode((string) file_get_contents($configPath), true);
        if (is_array($config)) {
            foreach ($config['deny'] ?? [] as $rule) {
                if (is_string($rule) && preg_match('/^Read\((.+)\)$/', trim($rule), $m)) {
                    $denyGlobs[] = $m[1];
                }
            }
            $extra = $config['protectedFiles'] ?? [];
            foreach (($extra['deny'] ?? []) as $g) {
                if (is_string($g)) {
                    $denyGlobs[] = $g;
                }
            }
            foreach (($extra['allow'] ?? []) as $g) {
                if (is_string($g)) {
                    $allowGlobs[] = $g;
                }
            }
        }
    }

    return [
        'deny'  => array_map('guardrails_glob_to_regex', array_values(array_unique($denyGlobs))),
        'allow' => array_map('guardrails_glob_to_regex', array_values(array_unique($allowGlobs))),
    ];
}

/** @param array<int,string> $regexes */
function guardrails_matches(string $path, array $regexes): bool
{
    $forms = guardrails_forms($path);
    foreach ($regexes as $re) {
        foreach ($forms as $form) {
            if (preg_match($re, $form) === 1) {
                return true;
            }
        }
    }
    return false;
}

/** True if the path is protected (matches a deny glob and no allow glob). */
function guardrails_is_protected(string $path): bool
{
    static $patterns = null;
    if ($patterns === null) {
        $patterns = guardrails_load_patterns();
    }

    if (guardrails_matches($path, $patterns['allow'])) {
        return false;
    }

    return guardrails_matches($path, $patterns['deny']);
}

function guardrails_block(string $path): never
{
    $name = basename(trim($path)) ?: $path;
    fwrite(STDERR, "Blocked: '{$name}' is a protected/secret file and is off-limits to Claude. Ask the user for any specific value you need.\n");
    exit(2);
}

/** True if any spelling of this shell word resolves to a protected file. */
function guardrails_word_hits(string $text): ?string
{
    foreach (ShellExpansion::braceExpand($text) as $candidate) {
        // Direct spelling (covers quote/escape/brace obfuscation).
        if (guardrails_is_protected($candidate)) {
            return $candidate;
        }
        // Runtime glob expansion against the real filesystem.
        foreach (ShellExpansion::glob($candidate) as $match) {
            if (guardrails_is_protected($match)) {
                return $match;
            }
        }
    }

    return null;
}

if (in_array($tool, ['Read', 'Edit', 'Write'], true)) {
    $path = (string) ($data['tool_input']['file_path'] ?? '');
    if ($path !== '' && guardrails_is_protected($path)) {
        guardrails_block($path);
    }
} elseif ($tool === 'Bash') {
    $command = (string) ($data['tool_input']['command'] ?? '');

    foreach (ShellExpansion::tokenize($command) as $word) {
        if ($word['text'] === '') {
            continue;
        }
        $hit = guardrails_word_hits($word['text']);
        if ($hit !== null) {
            guardrails_block($hit);
        }
    }
}

exit(0);
