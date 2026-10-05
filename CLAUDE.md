# ImagePlus (internal), claude-guardrails

## Project
Composer plugin (`imageplus/claude-guardrails`) that installs Claude Code guardrails into
ImagePlus Laravel/WordPress/Android projects. On `composer install`/`update` in a host project
it merges permission deny rules and `PreToolUse` hooks into that project's
`.claude/settings.json`. It blocks Vapor, the AWS CLI, secret files (`.env`, `wp-config*.php`, keys/certs,
`secrets.properties`, `~/.aws/*`, `bootstrap/cache/config.php`) and config disclosure
(`artisan config:show`, `php -r`/`-a`). `artisan tinker` is deliberately allowed.
PHP >= 8.1, no runtime dependencies. Private repo: `github.com/imageplus/claude-guardrails`.

## Commands
- `composer install`: dev deps (PHPUnit, composer/composer). The plugin skips its own repo.
- `composer test` (or `vendor/bin/phpunit`): full suite. `--filter ProtectedFilesTest` for one class.
- Manual hook check: `echo '{"tool_name":"Bash","tool_input":{"command":"cat .env"}}' | php hooks/protected-files.php; echo $?`
  (exit code `2` = blocked, `0` = allowed).

## Architecture
- `config/guardrails.json`: the single source of truth.
  - `deny`: Claude Code permission strings. `__PACKAGE_PATH__` gets swapped for the install path(s).
  - `protectedFiles.deny` / `.allow`: extra globs for the file hook.
  - `hooks`: `{matcher, script}` entries that point at `hooks/`.
- `src/Plugin.php`: runs on POST_INSTALL/POST_UPDATE and merges into the host project's settings.
  - Managed deny rules are tracked in `.claude/.guardrails-managed.json` and re-synced every run.
  - Our hooks are found by the package name in their command path, stripped, then re-added.
  - It never touches project-owned rules or hooks.
- `src/ShellExpansion.php`: shell-aware tokenising (quotes and escapes), command splitting,
  brace expansion and filesystem globbing. The hooks use it to see what a shell would actually run.
- `hooks/*.php`: standalone CLI scripts. They read tool-call JSON from stdin, write the reason
  to stderr and `exit(2)` to block.
  - `protected-files.php` also reads every `Read(<glob>)` in the `deny` list, so a new Read deny
    rule is enforced for Bash automatically.
- `tests/`: PHPUnit.
  - `tests/Hooks/*` run each hook as a real subprocess against a temp project dir.
  - `PluginTest` runs the merge against a temp host project with a symlinked `vendor/` install.

## Known Gotchas
- **Hooks fail open.** A missing script, a PHP fatal or any exit code other than `2` counts as
  *allow*. Never rename or move a hook without updating `config/guardrails.json`. A test checks
  that every configured script exists.
- Hooks run as `php <path>` outside the host project's autoloader. They must stay
  dependency-free: `require_once __DIR__ . '/../src/ShellExpansion.php'`, no Composer
  autoload, no packages.
- Match on intent, not substrings. Never key a rule off a common word like `config` or `aws`,
  because `config:cache`, `config/`, `git config` and `composer require aws/aws-sdk-php` must keep
  working. `block-aws.php` matches the *program* being run, not the word. A noisy rule gets switched off,
  which is worse than no rule. Split chained commands (`splitCommands`) before judging flags.
- Keep `Write(...)` and `Edit(...)` deny rules paired. They are separate tools.
- Path-repo installs are symlinks, so `__PACKAGE_PATH__` rules are emitted for both the
  `vendor/` path and the real in-project path.
- Glob deny patterns are broad: `**/.env.*` blocks `.env.testing.example`. Only the exact
  template names in `protectedFiles.allow` are readable.
- Variable expansion and command substitution (`$x`, `$(...)`) can't be resolved statically.
  That is a known, documented limitation, not a bug to chase.
- `sync()` must never throw. A failure warns and lets the host's `composer install` finish.

## What Not To Do
- Don't create git tags, cut releases or push. Releases are human-only.
- Don't add runtime `require` dependencies. They would get installed into every client project.
- Don't loosen a block (drop a deny rule, widen an allow glob, narrow a hook) without saying so
  explicitly and adding a test for the newly-allowed case.
- Don't change hook behaviour without tests covering both a blocked and an allowed command,
  including obfuscated spellings (quotes, escapes, braces, globs).
- Don't let README.md drift. It is the user-facing spec. Update "What lands in the project",
  "Config disclosure" and "Limitations" whenever `config/guardrails.json` or the hooks change.
