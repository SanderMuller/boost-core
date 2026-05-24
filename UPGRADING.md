# Upgrading

Breaking changes per major/minor bump.

## 0.6 → 0.7

0.7.0 adds `withRemoteSkills(...)` — declarative consumption of non-Composer skill sources (GitHub bundle releases, single-skill repos, mega-repo subdirs). Additive, no migration required.

Two operational notes for consumers who adopt the new API:

- **First sync hits the network.** Fetched archives are cached on disk so subsequent syncs are offline-fast, but the cold sync downloads each declared source. Anonymous GitHub access caps at 60 requests/hour — set `BOOST_GITHUB_TOKEN` (any token with `public_repo` scope) to lift it to 5000/h. CI runs that resolve `withRemoteSkills(...)` cold should always export the token.
- **`BOOST_REMOTE_STRICT=1`** escalates any remote-source failure (network unreachable, malformed archive, name-mismatch) to an aborting error. Default is warn-and-skip. Recommended for CI; leave unset for local dev.

`boost doctor` lists every declared remote source, flags moving refs (`'main'`, `'latest'`, branch names) with a `⚠`, and reports per-skill cache presence — all offline.

Removing a skill from `withRemoteSkills(...)` prunes its agent-dir output on the next sync; removing an entire source prunes every skill it last contributed. The pruning state lives at `<project>/.boost-remote-manifest.json`, auto-added to the managed `.gitignore`.

## 0.5 → 0.6

0.6.0 retires boost-core's Composer plugin. boost-core is now a plain `library` — it runs no install-time code, so there is no `allow-plugins` trust prompt and no install-time execution surface. Three things change for consumers.

### `composer boost:*` commands are gone — use `vendor/bin/boost`

The plugin registered `composer boost:install`, `composer boost:sync`, etc. as Composer subcommands. With the plugin removed, every command runs through the standalone binary, with the `boost:` prefix dropped:

| Was | Now |
|---|---|
| `composer boost:install` | `vendor/bin/boost install` |
| `composer boost:sync` | `vendor/bin/boost sync` |
| `composer boost:scan` | `vendor/bin/boost scan` |
| `composer boost:doctor` | `vendor/bin/boost doctor` |
| `composer boost:tags` | `vendor/bin/boost tags` |
| `composer boost:new` | `vendor/bin/boost new` |

Update any scripts, CI steps, or docs that invoked `composer boost:*`.

### Auto-sync no longer happens automatically

The plugin auto-ran `boost sync` on every `composer install` / `composer update` (the `post-autoload-dump` hook). That hook is gone. To keep a `composer install` re-syncing, wire the script callback into your **own root `composer.json`**:

```json
"scripts": {
    "post-install-cmd": ["SanderMuller\\BoostCore\\Scripts\\BoostAutoSync::run"],
    "post-update-cmd":  ["SanderMuller\\BoostCore\\Scripts\\BoostAutoSync::run"]
}
```

A dependency's own `post-install-cmd` does not fire in a consuming project — only the root package's scripts run — so this must go in your project's `composer.json`. Otherwise, run `vendor/bin/boost sync` yourself (e.g. in CI). `BOOST_SKIP_AUTOSYNC=1` still disables the callback.

### Globally-installed skill packages: `boost sync --scope=user --all`

The plugin also auto-synced skills for `composer global require`-d packages. That is replaced by an explicit command — after a global install, run once:

```bash
vendor/bin/boost sync --scope=user --all
```

It user-scope-syncs every globally-installed package that ships `resources/boost/skills/`, wholesale — every skill is published. Tag filters and the vendor allowlist are project-scope controls; with no `boost.php` in user scope, neither applies.

### Drop the now-dead `allow-plugins` entry

A consumer `composer.json` that listed `sandermuller/boost-core` under `config.allow-plugins` can remove that entry — boost-core is no longer a plugin. Leaving it is harmless (Composer ignores an `allow-plugins` entry for a non-plugin), so the cleanup is optional.

## 0.3 → 0.4

### User-scope skill paths now vendor-namespaced

Pre-0.4, user-scope skills landed at `~/.{agent}/skills/<package-basename>/...` — so `acme/repo-init` and `vendor-b/repo-init` would collide on `~/.{agent}/skills/repo-init/`. The 0.3 line shipped graceful warn-and-skip behaviour for collisions; 0.4 fixes the underlying cause by namespacing paths by the full `vendor/package` slug. The `/` is replaced with `__` (double underscore) — a sequence that the Composer name spec forbids inside vendor or project parts, which makes the slug mapping injective (no two distinct package names can produce the same slug).

| Was (0.3) | Now (0.4) |
|---|---|
| `~/.{agent}/skills/repo-init/...` (from `acme/repo-init`) | `~/.{agent}/skills/acme__repo-init/...` |
| `~/.{agent}/skills/repo-init/...` (from `vendor-b/repo-init` — would have warn-and-skip-collided in 0.3) | `~/.{agent}/skills/vendor-b__repo-init/...` (now coexists) |

**Migration steps:** none required by hand for the common case. The first `syncUser()` invocation against each installed package after the 0.4 bump runs a one-time auto-migration:

1. Detects `~/.{agent}/skills/<old-basename>/` for the package being synced
2. Verifies the legacy dir's contents are reproducible from THIS package's `resources/boost/skills/` tree (ownership check)
3. If the new `~/.{agent}/skills/<vendor>__<basename>/` dir does NOT already exist, renames
4. Idempotent: subsequent syncs find no old dir and no-op
5. Safe: if both old and new dirs exist (manually-created new dir, partial migration), skips the rename rather than clobbering

The migration is per-package and triggers naturally as each `composer global require`'d package's plugin / manual `composer boost:sync --scope=user` invocation happens. Packages uninstalled before their migration would have run leave their old basename dirs behind — same as the existing "stale-skills-on-`composer global remove`" known limitation.

**Pre-0.2 collision states require manual cleanup.** The collision-detection guard in `BoostCorePlugin::runGlobalSync` shipped in 0.2.0; pre-0.2, two installed packages with the same basename both wrote to `~/.{agent}/skills/<basename>/`, last-writer wins. Post-0.4, the auto-migration's ownership check refuses to rename such a dir (foreign files mean mis-attribution risk), and the legacy dir is left in place. To resolve: inspect `~/.{agent}/skills/<basename>/`, copy any wanted files to the right `~/.{agent}/skills/<vendor>__<basename>/` dir manually, then `rm -rf ~/.{agent}/skills/<basename>/`.

**For scripts / docs referencing user-scope paths**: update any hard-coded `~/.{agent}/skills/<basename>/` references to `~/.{agent}/skills/<vendor>__<basename>/`. Boost-core's collision-detection code path stays in place defensively but is unreachable for any valid Composer package name.

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
