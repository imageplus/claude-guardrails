# imageplus/claude-guardrails

Centralised [Claude Code](https://code.claude.com) guardrails for ImagePlus Laravel projects.

Installing this package into a project automatically wires five protections into
that project's `.claude/settings.json`:

1. **Vapor is blocked.** Claude cannot run any `vapor` command (deploy or
   otherwise). Deploys stay a manual, human-run action.
2. **`.env` is protected.** Claude cannot read, edit, copy or `source` a real
   `.env` file via its built-in tools or Bash. Template files
   (`.env.example`, `.env.sample`, `.env.dist`, `.env.template`) remain readable.
3. **WordPress credentials are protected.** `wp-config.php` and its per-environment
   variants (`wp-config-local.php`, `wp-config-staging.php`, and any other
   `wp-config-*.php`), `local-config.php`, `wp-salt.php`, `wp-cli.local.yml`,
   `~/.wp-cli/` and `.htpasswd` are off-limits — they carry DB credentials, auth
   salts and host aliases. `wp-config-sample.php` remains readable.
4. **Android secrets are protected.** `secrets.properties`, are off-limits. A project's own `gradle.properties`, `local.defaults.properties` and
   the Gradle build files stay readable.
5. **The app can't be asked to recite its secrets.** Protecting files only stops
   Claude reading the *file*; it does nothing about a command that boots the
   framework and prints the values loaded from it. `php artisan config:show`,
   `php -r` and `php -a` are blocked, and the secrets
   `php artisan config:cache` writes into `bootstrap/cache/config.php` are
   protected as a file. See "Config disclosure" below.

Enforcement is layered: static `deny` rules as a first line, plus `PreToolUse`
hooks (which block at exit code `2`, before permission rules are even evaluated)
as the reliable line.

> **Both layers are load-bearing, and they fail in opposite directions.** A hook
> blocks only on exit code `2`; if its script is missing or errors, Claude Code
> treats that as *allow*. So a broken hook path silently downgrades the project
> to deny-rules-only. If you move or delete the package directory, re-run
> `composer install` and check `/hooks` before trusting the setup.

## Installation

This is a private, first-party package. Add your internal repository to the
project's `composer.json`, then require it as a dev dependency:

```bash
composer require --dev imageplus/claude-guardrails
```

Because it is a Composer **plugin**, Composer 2.2+ will ask you to allow it to
run. Approve it, or pre-approve it in the project's `composer.json`:

```json
{
  "config": {
    "allow-plugins": {
      "imageplus/claude-guardrails": true
    }
  }
}
```

On the next `composer install` / `composer update`, the plugin merges its rules
into `.claude/settings.json`, creating the file (and the `.claude/` directory)
if they don't exist. Commit `.claude/settings.json` so every teammate inherits
the same guardrails.

## What lands in the project

`.claude/settings.json` gains (merged, not overwritten):

```jsonc
{
  "permissions": {
    "deny": [
      "Bash(*vapor*)",
      "Read(.env)",
      "Read(**/.env)",
      "Read(**/bootstrap/cache/config.php)",
      "Bash(php artisan config:show:*)",
      "Write(.claude/**)",
      "Edit(.claude/**)",
      "Write(vendor/imageplus/claude-guardrails/**)",
      "Edit(vendor/imageplus/claude-guardrails/**)"
    ]
  },
  "hooks": {
    "PreToolUse": [
      { "matcher": "Bash", "hooks": [{ "type": "command", "command": "php $CLAUDE_PROJECT_DIR/vendor/imageplus/claude-guardrails/hooks/block-vapor.php" }] },
      { "matcher": "Bash", "hooks": [{ "type": "command", "command": "php $CLAUDE_PROJECT_DIR/vendor/imageplus/claude-guardrails/hooks/block-config-disclosure.php" }] },
      { "matcher": "Read|Edit|Write|Bash", "hooks": [{ "type": "command", "command": "php $CLAUDE_PROJECT_DIR/vendor/imageplus/claude-guardrails/hooks/protected-files.php" }] }
    ]
  }
}
```

`Write(...)` and `Edit(...)` are both listed on purpose. Claude Code matches deny
rules per *tool*, and `Write` and `Edit` are separate tools — `Edit(.claude/**)`
alone leaves the directory wide open to a whole-file `Write`, which is the more
destructive of the two. Keep them paired.

When the package is installed from a Composer **path repository**, the install
path under `vendor/` is a symlink. A deny rule on a symlink does not protect its
target, so the plugin emits rules for both spellings — e.g. both
`Edit(vendor/imageplus/claude-guardrails/**)` and `Edit(claude-guardrails/**)`.
This only works while the real directory sits *inside* the project: permission
globs are project-relative and cannot name a path outside the project root. If
you keep the package checkout elsewhere, the package cannot protect itself from
edits, and you are relying on it being a separate repo with its own review.

A sidecar file, `.claude/.guardrails-managed.json`, records which deny rules the
package owns so it can keep them in sync on future updates. Leave it in place.

## Updating

Bump the version and run `composer update imageplus/claude-guardrails` across
your projects. The plugin re-syncs on every install/update: it strips the hook
entries and deny rules it previously added and writes the current set, so
changes here propagate everywhere without hand-editing any project.

## Config disclosure

File rules see filenames. They cannot see this:

```bash
php artisan config:show database    # prints the DB password, no filename involved
```

`block-config-disclosure.php` covers that route. It blocks `config:show`,
`php -r` and `php -a`.

It deliberately does **not** block:

- **`php artisan config:cache`** — routine, and blocking it would break real
  work. Instead the secrets it writes into `bootstrap/cache/config.php` are
  denied as a file. This is the non-obvious one: a harmless-looking, frequently
  run command materialises every resolved secret into plaintext at a path no
  `.env` rule covers.
- **`php artisan about`** — reports drivers, versions and environment names, no
  credentials.
- **`php artisan tinker`** — allowed by choice as everyday debugging tooling.
  Be aware it runs arbitrary code in the booted app, so `tinker --execute` can
  print config values; that trade-off is accepted.

The hook matches on intent, never on the substring `config`. Projects have a
`config/` directory, `config:clear` is routine, and `git config` / `npm config`
are everyday commands; a rule keying off that word fires constantly and gets
switched off, which is worse than no rule. Commands are split on `;`, `&&`,
`||` and `|` first, so `php artisan test && grep -r foo .` is not misread as
`php … -r`.

To relax it for a project, drop an entry from `GUARDRAILS_ARTISAN_DENY` at the
top of the hook.

## Extending / changing what's blocked

Edit `config/guardrails.json` in this package:

- **`deny`** — plain Claude Code permission strings. Use the `__PACKAGE_PATH__`
  token where you need this package's install path (the plugin substitutes the
  real relative path).
- **`hooks`** — each entry is `{ "matcher": "...", "script": "..." }`, where
  `script` is a file in `hooks/`. Add a new PHP hook file and reference it here.

Hook scripts read the tool-call JSON from stdin (`tool_name`,
`tool_input.command`, `tool_input.file_path`) and exit `2` to block.

## Verifying it works

In a project after install:

```
/permissions   # confirm the deny rules loaded
/hooks         # confirm all three PreToolUse hooks are registered
```

Check the hook paths actually resolve — a dangling path fails *open*:

```bash
for h in block-vapor block-config-disclosure protected-files; do
  test -f "vendor/imageplus/claude-guardrails/hooks/$h.php" \
    && echo "ok   $h" || echo "MISSING $h — this project is unprotected"
done
```

Then ask Claude to run `./vendor/bin/vapor deploy production` (should block) and
to `cat .env` (should block) versus `cat .env.example` (should succeed). On a
WordPress project, `cat wp-config.php` should block while
`cat wp-config-sample.php` succeeds. For the config layer, `php artisan
config:show database` should block while `php artisan config:cache` succeeds.

## Limitations (be honest about these)

- Hooks can't see a filename that never appears in the command, so "no process
  ever touches `.env`" is not what this provides. Where that *does* hand the
  values to Claude, it is handled explicitly — see "Config disclosure" above.
  For OS-level enforcement, use Claude Code's sandbox.
- A command whose target is assembled purely by variable expansion
  (`c=config:show; php artisan $c`) or command substitution cannot be resolved
  statically by any of the hooks. They de-obfuscate quoting, brace alternation
  and globs; they are not a shell interpreter.
- Referenced scripts live in `vendor/`, which Claude *can* normally write to;
  that's why the package denies writes/edits to its own directory. If you want
  the scripts inside the already-protected `.claude/` dir instead, switch the
  plugin to copy them rather than reference them (you lose one-command updates).
- `deny` rules have a history of reliability bugs in Claude Code; the hooks are
  the dependable layer and the reason they exist.
- This package ships code that auto-executes in the dev loop. Keep it private
  and first-party, and review changes like any other security tooling.
