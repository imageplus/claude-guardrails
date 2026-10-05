#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * PreToolUse hook: block the AWS CLI.
 *
 * Claude must not act on AWS accounts directly — no reads of buckets, secrets
 * or parameters, and no changes to infrastructure. Those stay human-run.
 *
 * Unlike `vapor`, the word "aws" is everywhere in harmless commands
 * (`composer require aws/aws-sdk-php`, `grep aws config/filesystems.php`,
 * `cd aws-infra`), so this hook does NOT block on the word. It blocks when the
 * AWS CLI is what actually runs:
 *
 *   - the program of a command is `aws` (any path, e.g. /usr/local/bin/aws),
 *     including behind wrappers such as `sudo`, `env FOO=1`, `time`, `xargs`;
 *   - the CLI is reached another way: `python -m awscli`, the `amazon/aws-cli`
 *     Docker image, or a `sh -c '...'` / `eval '...'` string that runs `aws`.
 *
 * Commands are split on `;`, `&&`, `||` and `|` first, and tokens are unquoted,
 * brace-expanded and globbed, so `a''ws s3 ls`, `{aws,true} s3 ls` and
 * `./bin/aw?` resolve before the check.
 *
 * Residual limitation: a program name assembled purely from variable expansion
 * (`c=aws; $c s3 ls`) or command substitution cannot be resolved statically.
 * Defence-in-depth — the real control is not leaving AWS credentials with
 * broad permissions on developer machines.
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

/** Programs that run the next word as a command (their own flags are skipped). */
const GUARDRAILS_WRAPPERS = [
    'sudo', 'env', 'command', 'exec', 'time', 'nohup', 'nice', 'xargs',
    'doas', 'timeout', 'stdbuf', 'caffeinate', 'aws-vault', 'npx', 'pipx', 'uvx',
];

/** Shells whose `-c` argument is itself a command line. */
const GUARDRAILS_SHELLS = ['sh', 'bash', 'zsh', 'dash', 'ksh', 'fish'];

function guardrails_block(): never
{
    fwrite(STDERR, "Blocked: AWS CLI commands must be run manually from the terminal, not by Claude. "
        . "Ask the user to run it and share the output you need.\n");
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

/** @param array<int,string> $spellings */
function guardrails_is_aws(array $spellings): bool
{
    foreach ($spellings as $s) {
        if (preg_match('/^aws(2|\.exe|\.cmd)?$/i', basename($s))) {
            return true;
        }
    }

    return false;
}

/** True if this command line (possibly several commands) runs the AWS CLI. */
function guardrails_runs_aws(string $line, int $depth = 0): bool
{
    if ($depth > 3) {
        return false;
    }

    foreach (ShellExpansion::splitCommands($line) as $segment) {
        $tokens = array_column(ShellExpansion::tokenize($segment), 'text');

        // Find the program: skip VAR=value assignments, wrappers and their flags.
        $programIndex = null;
        foreach ($tokens as $i => $token) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*=/', $token) || str_starts_with($token, '-')) {
                continue;
            }
            $base = strtolower(basename($token));
            if (\in_array($base, GUARDRAILS_WRAPPERS, true) || is_numeric($token)) {
                continue;               // `timeout 30 aws ...` — skip the duration too
            }
            $programIndex = $i;
            break;
        }
        if ($programIndex === null) {
            continue;
        }

        $program = $tokens[$programIndex];
        if (guardrails_is_aws(guardrails_expand($program))) {
            return true;
        }

        // `aws-vault exec profile -- aws s3 ls`: whatever follows `--` is a command.
        $sep = array_search('--', $tokens, true);
        if ($sep !== false && isset($tokens[$sep + 1])
            && guardrails_runs_aws(implode(' ', array_map('escapeshellarg', \array_slice($tokens, $sep + 1))), $depth + 1)
        ) {
            return true;
        }

        $base = strtolower(basename($program));
        $rest = \array_slice($tokens, $programIndex + 1);

        // sh -c 'aws s3 ls' / eval 'aws s3 ls': check the inner command line.
        if (\in_array($base, GUARDRAILS_SHELLS, true)) {
            $c = array_search('-c', $rest, true);
            if ($c !== false && isset($rest[$c + 1]) && guardrails_runs_aws($rest[$c + 1], $depth + 1)) {
                return true;
            }
        }
        if ($base === 'eval' && guardrails_runs_aws(implode(' ', $rest), $depth + 1)) {
            return true;
        }

        // python -m awscli
        if (preg_match('/^python(\d+(\.\d+)?)?$/', $base)) {
            $m = array_search('-m', $rest, true);
            if ($m !== false && strtolower($rest[$m + 1] ?? '') === 'awscli') {
                return true;
            }
        }

        // docker run amazon/aws-cli ...
        if ($base === 'docker' || $base === 'podman') {
            foreach ($rest as $word) {
                if (preg_match('#(^|/)amazon/aws-cli(:|$)#i', $word)) {
                    return true;
                }
            }
        }
    }

    return false;
}

if (guardrails_runs_aws($command)) {
    guardrails_block();
}

exit(0);
