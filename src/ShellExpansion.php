<?php

declare(strict_types=1);

namespace ImagePlus\ClaudeGuardrails;

/**
 * Shell-aware expansion helpers shared by the PreToolUse hooks.
 *
 * These let a hook see the filenames/commands a shell would actually run,
 * rather than the raw command text. Quotes and backslash escapes are removed,
 * brace alternations are expanded, and glob patterns are resolved against the
 * real filesystem — closing the class of bypass where the shell computes a
 * target at runtime (`.???`, `va''por`, `vap{or,e}`, `.../bin/vap*`) without
 * the literal token ever appearing in the command.
 *
 * Residual limitation: names produced purely by variable expansion (`$f`) or
 * command substitution (`$(...)`) cannot be resolved statically. Tokens that
 * contain such constructs are flagged `dynamic` so callers can decide how
 * conservative to be. Treat hooks built on this as defence-in-depth; the real
 * control for secrets is filesystem permissions.
 */
final class ShellExpansion
{
    /**
     * Split a shell command into words the way the shell would: honour single
     * quotes, double quotes and backslash escapes (all removed from the result),
     * and break on unquoted whitespace and shell operators. Glob/brace
     * metacharacters that survive quoting stay in the word for later expansion.
     * Words containing $ or backticks are flagged dynamic.
     *
     * @return array<int,array{text:string,dynamic:bool}>
     */
    public static function tokenize(string $cmd): array
    {
        $words = [];
        $buf = '';
        $inWord = false;
        $dynamic = false;
        $i = 0;
        $n = strlen($cmd);

        $flush = static function () use (&$words, &$buf, &$inWord, &$dynamic): void {
            if ($inWord) {
                $words[] = ['text' => $buf, 'dynamic' => $dynamic];
            }
            $buf = '';
            $inWord = false;
            $dynamic = false;
        };

        while ($i < $n) {
            $c = $cmd[$i];

            if ($c === "'") {                       // single quotes: fully literal
                $inWord = true;
                $i++;
                while ($i < $n && $cmd[$i] !== "'") {
                    $buf .= $cmd[$i];
                    $i++;
                }
                $i++;
                continue;
            }

            if ($c === '"') {                        // double quotes: allow \ escapes
                $inWord = true;
                $i++;
                while ($i < $n && $cmd[$i] !== '"') {
                    $ch = $cmd[$i];
                    if ($ch === '\\' && $i + 1 < $n && strpos('"\\$`', $cmd[$i + 1]) !== false) {
                        $i++;
                        $ch = $cmd[$i];
                    }
                    if ($ch === '$' || $ch === '`') {
                        $dynamic = true;
                    }
                    $buf .= $ch;
                    $i++;
                }
                $i++;
                continue;
            }

            if ($c === '\\') {                       // backslash escape: next char literal
                $i++;
                if ($i < $n) {
                    $buf .= $cmd[$i];
                    $inWord = true;
                    $i++;
                }
                continue;
            }

            if (ctype_space($c)) {                   // word separator
                $flush();
                $i++;
                continue;
            }

            if (strpos('|&;<>()', $c) !== false) {   // shell operators separate words
                $flush();
                $i++;
                continue;
            }

            if ($c === '$' || $c === '`') {
                $dynamic = true;
            }

            $buf .= $c;
            $inWord = true;
            $i++;
        }

        $flush();

        return $words;
    }

    /**
     * Split a command line into the individual commands a shell would run,
     * breaking on `;`, `&&`, `||`, `|` and newlines while ignoring separators
     * inside quotes or behind a backslash. Quotes are preserved so the result
     * can be fed straight back into tokenize().
     *
     * Callers that judge a command by the flags next to it need this: without
     * it, `php artisan test && grep -r foo .` looks like one word list in which
     * `php` and `-r` sit side by side, and any "php with -r" rule misfires.
     *
     * A heredoc body is split on its newlines like anything else. That is
     * deliberate — a heredoc piped into an interpreter is a way to smuggle a
     * command, so its lines deserve the same scrutiny as the outer command.
     *
     * @return array<int,string>
     */
    public static function splitCommands(string $cmd): array
    {
        $segments = [];
        $buf = '';
        $i = 0;
        $n = strlen($cmd);

        while ($i < $n) {
            $c = $cmd[$i];

            if ($c === "'") {                        // single quotes: copy verbatim
                $buf .= $c;
                $i++;
                while ($i < $n && $cmd[$i] !== "'") {
                    $buf .= $cmd[$i];
                    $i++;
                }
                if ($i < $n) {
                    $buf .= $cmd[$i];
                    $i++;
                }
                continue;
            }

            if ($c === '"') {                        // double quotes: keep \ escapes intact
                $buf .= $c;
                $i++;
                while ($i < $n && $cmd[$i] !== '"') {
                    if ($cmd[$i] === '\\' && $i + 1 < $n) {
                        $buf .= $cmd[$i];
                        $i++;
                    }
                    $buf .= $cmd[$i];
                    $i++;
                }
                if ($i < $n) {
                    $buf .= $cmd[$i];
                    $i++;
                }
                continue;
            }

            if ($c === '\\' && $i + 1 < $n) {        // escaped char is never a separator
                $buf .= $c . $cmd[$i + 1];
                $i += 2;
                continue;
            }

            if ($c === ';' || $c === "\n" || $c === '|' || $c === '&') {
                $segments[] = $buf;
                $buf = '';
                $i++;
                // Swallow the rest of a multi-character operator (&&, ||, ;;).
                while ($i < $n && ($cmd[$i] === '|' || $cmd[$i] === '&' || $cmd[$i] === ';')) {
                    $i++;
                }
                continue;
            }

            $buf .= $c;
            $i++;
        }

        $segments[] = $buf;

        return array_values(array_filter(
            array_map('trim', $segments),
            static fn (string $s): bool => $s !== ''
        ));
    }

    /**
     * Expand top-level brace alternations, e.g. ".e{n,x}v" -> [".env", ".exv"].
     * Non-alternation braces (no comma) are left untouched.
     *
     * @return array<int,string>
     */
    public static function braceExpand(string $s): array
    {
        $open = strpos($s, '{');
        if ($open === false) {
            return [$s];
        }

        $depth = 0;
        $close = -1;
        for ($i = $open, $len = strlen($s); $i < $len; $i++) {
            if ($s[$i] === '{') {
                $depth++;
            } elseif ($s[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    $close = $i;
                    break;
                }
            }
        }
        if ($close === -1) {
            return [$s]; // unbalanced — leave as-is
        }

        $pre = substr($s, 0, $open);
        $post = substr($s, $close + 1);
        $inner = substr($s, $open + 1, $close - $open - 1);

        // Split inner on top-level commas.
        $parts = [];
        $cur = '';
        $depth = 0;
        for ($i = 0, $len = strlen($inner); $i < $len; $i++) {
            $ch = $inner[$i];
            if ($ch === '{') {
                $depth++;
                $cur .= $ch;
            } elseif ($ch === '}') {
                $depth--;
                $cur .= $ch;
            } elseif ($ch === ',' && $depth === 0) {
                $parts[] = $cur;
                $cur = '';
            } else {
                $cur .= $ch;
            }
        }
        $parts[] = $cur;

        if (count($parts) === 1) {
            // Not a real alternation; keep the braces literal, expand any tail.
            $results = [];
            foreach (self::braceExpand($post) as $tail) {
                $results[] = $pre . '{' . $inner . '}' . $tail;
            }
            return $results;
        }

        $results = [];
        foreach ($parts as $part) {
            foreach (self::braceExpand($pre . $part . $post) as $expanded) {
                $results[] = $expanded;
            }
        }

        return array_values(array_unique($results));
    }

    /**
     * Expand a glob pattern against likely base directories (the project root
     * and the current working directory), returning matched real paths. Returns
     * an empty array when the pattern contains no glob metacharacters.
     *
     * @return array<int,string>
     */
    public static function glob(string $pattern): array
    {
        if (!preg_match('/[*?\[]/', $pattern)) {
            return []; // nothing to expand
        }

        $patterns = [];
        if (str_starts_with($pattern, '/')) {
            $patterns[] = $pattern;
        } else {
            $bases = array_values(array_unique(array_filter([
                getenv('CLAUDE_PROJECT_DIR') ?: null,
                getcwd() ?: null,
            ])));
            if ($bases === []) {
                $bases = ['.'];
            }
            foreach ($bases as $base) {
                $patterns[] = rtrim($base, '/') . '/' . $pattern;
            }
        }

        $matches = [];
        foreach ($patterns as $p) {
            foreach (glob($p, GLOB_NOSORT) ?: [] as $m) {
                $matches[] = $m;
            }
        }

        return $matches;
    }
}