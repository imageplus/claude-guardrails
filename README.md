# imageplus/claude-guardrails

Centralised [Claude Code](https://code.claude.com) guardrails for ImagePlus Laravel projects.

Installing this package into a project automatically wires four protections into
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

Enforcement is layered: static `deny` rules as a first line, plus `PreToolUse`
hooks (which block at exit code `2`, before permission rules are even evaluated)
as the reliable line.

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
      "Write(.claude/**)",
      "Edit(.claude/**)",
      "Write(vendor/imageplus/claude-guardrails/**)",
      "Edit(vendor/imageplus/claude-guardrails/**)"
    ]
  },
  "hooks": {
    "PreToolUse": [
      { "matcher": "Bash", "hooks": [{ "type": "command", "command": "php $CLAUDE_PROJECT_DIR/vendor/imageplus/claude-guardrails/hooks/block-vapor.php" }] },
      { "matcher": "Read|Edit|Write|Bash", "hooks": [{ "type": "command", "command": "php $CLAUDE_PROJECT_DIR/vendor/imageplus/claude-guardrails/hooks/protect-env.php" }] }
    ]
  }
}
```

A sidecar file, `.claude/.guardrails-managed.json`, records which deny rules the
package owns so it can keep them in sync on future updates. Leave it in place.

## Updating

Bump the version and run `composer update imageplus/claude-guardrails` across
your projects. The plugin re-syncs on every install/update: it strips the hook
entries and deny rules it previously added and writes the current set, so
changes here propagate everywhere without hand-editing any project.

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
/hooks         # confirm both PreToolUse hooks are registered
```

Then ask Claude to run `./vendor/bin/vapor deploy production` (should block) and
to `cat .env` (should block) versus `cat .env.example` (should succeed). On a
WordPress project, `cat wp-config.php` should block while
`cat wp-config-sample.php` succeeds.

## Limitations (be honest about these)

- Hooks can't see a filename that never appears in the command — e.g.
  `php artisan config:cache` reads `.env` internally. That doesn't expose the
  contents to Claude, so it's fine, but it means "no process ever touches .env"
  is not what this provides. For OS-level enforcement, use Claude Code's
  sandbox.
- Referenced scripts live in `vendor/`, which Claude *can* normally write to;
  that's why the package denies writes/edits to its own directory. If you want
  the scripts inside the already-protected `.claude/` dir instead, switch the
  plugin to copy them rather than reference them (you lose one-command updates).
- `deny` rules have a history of reliability bugs in Claude Code; the hooks are
  the dependable layer and the reason they exist.
- This package ships code that auto-executes in the dev loop. Keep it private
  and first-party, and review changes like any other security tooling.
