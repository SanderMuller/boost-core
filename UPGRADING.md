# Upgrading

Breaking changes per major/minor bump.

## 0.2 → 0.3

### `composer boost:init` removed

`boost:install` now handles starter-file generation itself when `boost.php` is missing. The previous two-step flow (`composer boost:init` then `composer boost:install`) collapses into a single `composer boost:install` invocation that:

1. Generates a starter `boost.php` at the project root if none exists.
2. Loads the (now-existing) `boost.php`.
3. Opens the interactive picker for agents + vendor allowlist.
4. Writes the picked choices back via AST modification.

If `boost.php` already exists, step 1 is skipped and the command behaves exactly as it did in 0.2 (interactive picker only).

**Migration steps:**

| Was | Now |
|---|---|
| `composer boost:init && composer boost:install` | `composer boost:install` |
| `composer boost:init --force` | Delete `boost.php` by hand, then `composer boost:install` |
| CI script calling `boost:init` | Replace with `boost:install` (interactive — pin agents/vendors in committed `boost.php` if running in CI) |
| Docs/READMEs mentioning `boost:init` | Replace with `boost:install` and note it auto-generates the starter |

The `BoostConfigNotFoundException` error message now points at `composer boost:install` (was `composer boost:init`); update any error-handling code that grepped the old text.

No data migration is required — `boost.php`'s shape is unchanged.

## 0.1 → 0.2

See the [0.2.0 release notes](https://github.com/sandermuller/boost-core/releases/tag/0.2.0) for the full set of changes (auto-sync in `composer global` context, always-dir-form skill output with automatic legacy-sibling cleanup, `BOOST_SKIP_GITIGNORE=1` env-var escape hatch, `BaseCommandAdapter` plugin fix).

No code-level migration steps are required — the only manual cleanup is for projects that previously had flat `.{agent}/skills/<name>.md` outputs committed to git: those should be removed and replaced with the new `.{agent}/skills/<name>/SKILL.md` shape (the sync prunes them on first run if they're still on disk; only git-tracked copies need a separate `git rm`).
