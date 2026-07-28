#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * PreToolUse hook: block any Vapor command.
 *
 * Reads the tool call JSON from stdin. If the Bash command references `vapor`
 * as a standalone word, it exits with code 2 which tells Claude Code to block
 * the call and feeds the stderr message back to Claude.
 *
 * The match does NOT rely on the literal string "vapor" appearing in the raw
 * command. It uses the shared ShellExpansion helpers to tokenise, brace-expand
 * and glob the command the way a shell would, so runtime-computed spellings
 * such as `va''por`, `va\por`, `va"po"r`, `.../bin/vap*` and `.../bin/vap?r`
 * are resolved before the check.
 *
 * Residual limitation: an invocation built purely from variable expansion
 * (`v=vap; ${v}or`) or command substitution cannot be resolved statically.
 * Treat this hook as defence-in-depth.
 */

require_once __DIR__ . '/../src/ShellExpansion.php';

use ImagePlus\ClaudeGuardrails\ShellExpansion;

$raw  = stream_get_contents(STDIN) ?: '';
$data = json_decode($raw, true);
$data = is_array($data) ? $data : [];

$tool = $data['tool_name'] ?? '';
if ($tool !== 'Bash') {
    exit(0);
}

$command = (string) ($data['tool_input']['command'] ?? '');

/** True if the text mentions `vapor` as a whole word (case-insensitive). */
function guardrails_mentions_vapor(string $text): bool
{
    $normalized = preg_replace('/\s+/', ' ', $text) ?? $text;

    // Whole word: catches bare `vapor`, ./vendor/bin/vapor, php .../vapor,
    // and vapor:deploy, but not paths that merely contain the letters.
    return (bool) preg_match('/(^|[^A-Za-z0-9_])vapor([^A-Za-z0-9_]|$)/i', $normalized);
}

function guardrails_block(): never
{
    fwrite(STDERR, "Blocked: Vapor commands must be run manually from the terminal, not by Claude.\n");
    exit(2);
}

$tokens = ShellExpansion::tokenize($command);

// 1. Whole-word check on the de-obfuscated command (quotes/escapes removed).
if (guardrails_mentions_vapor(implode(' ', array_column($tokens, 'text')))) {
    guardrails_block();
}

// 2. Glob expansion: a pattern like ./vendor/bin/vap* resolves to the real
//    vapor binary even though the literal word never appears in the command.
foreach ($tokens as $token) {
    foreach (ShellExpansion::braceExpand($token['text']) as $candidate) {
        // Brace alternation may itself spell out vapor, e.g. vap{or,e}.
        if (guardrails_mentions_vapor($candidate)) {
            guardrails_block();
        }
        foreach (ShellExpansion::glob($candidate) as $match) {
            if (guardrails_mentions_vapor($match) || strtolower(basename($match)) === 'vapor') {
                guardrails_block();
            }
        }
    }
}

exit(0);
