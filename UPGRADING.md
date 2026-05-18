# Upgrading

Breaking changes per major/minor bump.

## 0.3 → 0.4

### User-scope skill paths now vendor-namespaced

Pre-0.4, user-scope skills landed at `~/.{agent}/skills/<package-basename>/...` — so `acme/repo-init` and `vendor-b/repo-init` would collide on `~/.{agent}/skills/repo-init/`. The 0.3 line shipped graceful warn-and-skip behaviour for collisions; 0.4 fixes the underlying cause by namespacing paths by the full `vendor/package` slug.

| Was (0.3) | Now (0.4) |
|---|---|
| `~/.{agent}/skills/repo-init/...` (from `acme/repo-init`) | `~/.{agent}/skills/acme-repo-init/...` |
| `~/.{agent}/skills/repo-init/...` (from `vendor-b/repo-init` — would have warn-and-skip-collided in 0.3) | `~/.{agent}/skills/vendor-b-repo-init/...` (now coexists) |

**Migration steps:** none required by hand. The first `syncUser()` invocation against each installed package after the 0.4 bump runs a one-time auto-migration:

1. Detects `~/.{agent}/skills/<old-basename>/` for the package being synced
2. If the new `~/.{agent}/skills/<vendor>-<basename>/` dir does NOT already exist, renames
3. Idempotent: subsequent syncs find no old dir and no-op
4. Safe: if both old and new dirs exist (manually-created new dir, partial migration), skips the rename rather than clobbering

The migration is per-package and triggers naturally as each `composer global require`'d package's plugin / manual `composer boost:sync --scope=user` invocation happens. Packages uninstalled before their migration would have run leave their old basename dirs behind — same as the existing "stale-skills-on-`composer global remove`" known limitation.

**For scripts / docs referencing user-scope paths**: update any hard-coded `~/.{agent}/skills/<basename>/` references to `~/.{agent}/skills/<vendor>-<basename>/`. Boost-core's collision-detection code path stays in place defensively but is unreachable in practice now (different vendors can't collide).

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
