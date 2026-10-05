#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * PreToolUse hook: stop Claude asking the application to recite its own secrets.
 *
 * The file-based guardrails only see filenames. They cannot see a command that
 * boots the framework and prints the values that were loaded *from* a protected
 * file — the secret arrives without the filename ever being mentioned:
 *
 *   php artisan config:show database        # prints the DB password outright
 *   php -r '...'                            # arbitrary code, reads any file
 *
 * This hook closes that route. It matches on *intent*, never on the word
 * "config": a project has a `config/` directory, `config:cache` is routine, and
 * `git config` / `npm config` are everyday commands, so anything keying off that
 * substring would fire constantly and get switched off. Only the specific
 * disclosure spellings above are blocked.
 *
 * Deliberately NOT blocked:
 *   - `php artisan config:cache` — routine, and the secret it writes is guarded
 *     at the file layer instead (Read(**\/bootstrap/cache/config.php)). Blocking
 *     the command would break real work; guarding its output does not.
 *   - `php artisan about` — reports drivers, versions and environment *names*,
 *     no credentials. Useful diagnostics, so it stays.
 *   - `php artisan tinker` — allowed by choice. It is day-to-day debugging
 *     tooling, and the trade-off is accepted that it can run code in a booted app.
 *
 * Like the sibling hooks, the check runs on the de-obfuscated command: tokens
 * are unquoted, brace-expanded and globbed, so `php arti*an config:show`,
 * `config:sh''ow` and `/usr/bin/php8.3 -r` all resolve before the comparison.
 *
 * Residual limitation: an invocation assembled purely from variable expansion
 * (`c=config:show; php artisan $c`) or command substitution cannot be resolved
 * statically. Defence-in-depth, not containment — the real control for secrets
 * is to keep production values out of the developer's filesystem entirely.
 */

require_once __DIR__ . '/../src/ShellExpansion.php';

use ImagePlus\ClaudeGuardrails\ShellExpansion;

$raw  = stream_get_contents(STDIN) ?: '';
$data = json_decode($raw, true);
$data = is_array($data) ? $data : [];

if (($data['tool_name'] ?? '') !== 'Bash') {
    exit(0);
}

$command = (string) ($data['tool_input']['command'] ?? '');

/**
 * Artisan subcommands that hand Claude resolved config values.
 */
const GUARDRAILS_ARTISAN_DENY = ['config:show'];

/** PHP CLI flags that execute code supplied on the command line or stdin. */
const GUARDRAILS_PHP_CODE_FLAGS = ['-r', '-a'];

function guardrails_block(string $what): never
{
    fwrite(STDERR, sprintf(
        "Blocked: `%s` can print resolved configuration values (database password, API keys, "
        . "app key) that are protected at the file layer. Run it yourself if you need the "
        . "output, or tell Claude the specific value it needs.\n",
        $what
    ));
    exit(2);
}

/**
 * Every literal spelling a token could resolve to: itself, its brace
 * alternations, and anything those glob to on disk.
 *
 * @return array<int,string>
 */
function guardrails_expand(string $token): array
{
    $out = [];
    foreach (ShellExpansion::braceExpand($token) as $candidate) {
        $out[] = $candidate;
        foreach (ShellExpansion::glob($candidate) as $match) {
            $out[] = $match;
        }
    }

    return $out;
}

/** True if the basename names a PHP CLI binary (php, php8.3, php.exe). */
function guardrails_is_php_binary(string $word): bool
{
    $base = strtolower(basename($word));
    $base = preg_replace('/\.(exe|bat)$/', '', $base) ?? $base;

    return (bool) preg_match('/^php(\d+(?:\.\d+)?)?$/', $base);
}

// Judge each command in the line separately, so flags belonging to one command
// are never read as belonging to another.
foreach (ShellExpansion::splitCommands($command) as $segment) {
    $words = [];
    foreach (ShellExpansion::tokenize($segment) as $token) {
        foreach (guardrails_expand($token['text']) as $word) {
            $words[] = $word;
        }
    }

    $invokesArtisan = false;
    $invokesPhp     = false;
    foreach ($words as $word) {
        if (strtolower(basename($word)) === 'artisan') {
            $invokesArtisan = true;
        }
        if (guardrails_is_php_binary($word)) {
            $invokesPhp = true;
        }
    }

    if ($invokesArtisan) {
        foreach ($words as $word) {
            if (\in_array(strtolower($word), GUARDRAILS_ARTISAN_DENY, true)) {
                guardrails_block('php artisan ' . strtolower($word));
            }
        }
    }

    if ($invokesPhp) {
        foreach ($words as $word) {
            // Exact match, or a flag with the code attached (php -r'echo 1;').
            $flag = substr($word, 0, 2);
            if (\in_array($word, GUARDRAILS_PHP_CODE_FLAGS, true)
                || ($flag === '-r' && \strlen($word) > 2)
            ) {
                guardrails_block('php ' . $flag);
            }
        }
    }
}

exit(0);
