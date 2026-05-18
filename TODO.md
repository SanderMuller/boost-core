# TODO — boost-* family

Handoff doc. Caveman style, dense. Covers all four repos: `boost-core`, `package-boost-php`, `package-boost-laravel`, `project-boost`. Read the whole thing — items 1–4 have hard ordering.

## Family layout

| Repo | Role | Composer deps inside family |
|---|---|---|
| `boost-core` | Foundation: plugin + standalone bin, 9 agents, sync engine, managed `.gitignore`, FileEmitter contract | (none) |
| `package-boost-php` | Framework-agnostic Composer-package authors. 5 skills + 2 commands (`lean`, `gitattributes`) | `boost-core` |
| `package-boost-laravel` | Laravel-package authors. 3 skills + `McpJsonEmitter` | `boost-core`, `package-boost-php` |
| `project-boost` | PHP application developers. 5 skills, pure bundle, no PHP code | `boost-core` |

GitHub: `github.com/SanderMuller/<name>`. All four on Packagist.

## Tag state and constraints

| Repo | Tags on Packagist | `require` on `boost-core` | Branch-alias |
|---|---|---|---|
| `boost-core` | `0.1.0`, `0.1.1`, `0.1.2`, `0.2.0` | — | `dev-main → 1.x-dev` |
| `package-boost-php` | `0.1.0`, `0.1.1` | `^1.0@dev` | `dev-main → 1.x-dev` |
| `package-boost-laravel` | none | `^1.0@dev` (also `package-boost-php: ^1.0@dev`) | `dev-main → 1.x-dev` |
| `project-boost` | none | `^1.0@dev` | `dev-main → 1.x-dev` |

**Problem.** Every consumer constrains `^1.0@dev`. There is no `1.x` tag. Constraint resolves ONLY via `dev-main` (branch-aliased to `1.x-dev`). The actual `0.x` tags exist on Packagist but the family doesn't pin to them. Tagged releases are effectively useless to consumers today. **Fix this first — see item #1.**

---

## 1. Decide the version stream

Pick ONE of these. Everything else waits on this decision.

**Option A — Stay on 0.x, fix the alias. (RECOMMENDED)**
- Bump branch-alias in all four `composer.json` files: `dev-main → 0.3.x-dev` (bump the minor as you cut each release).
- After #2 ships boost-core 0.3.0, consumer `require` constraints become `sandermuller/boost-core: ^0.3`. Note: Composer's caret on 0.y.z is special — `^0.3` means `>=0.3.0, <0.4.0`. Each minor bump needs a fresh constraint update in consumers.
- Pro: matches the conservative 0.x story already published; absorbs the planned breaking changes (#7, #8) before v1; FileEmitter can keep its `@experimental` annotation honestly.
- Con: every consumer needs a re-tag whenever boost-core bumps minor.

**Option B — Jump to 1.0 now.**
- Tag `boost-core 1.0.0` (with the BREAKING `boost:init` removal from CHANGELOG Unreleased).
- Keep branch-alias `1.x-dev` (already correct).
- Keep consumer constraints `^1.0@dev` until 1.0 ships, then switch to `^1.0`.
- Pro: branch-alias already matches; one decisive cut.
- Con (deal-breaker): items #7 + #8 are both user-visible breaking changes (manifest path + slug rename). Under B they force a 2.0 cut. Either delay #7/#8 indefinitely (bad: known limitations stay known) or burn the credibility of v1 by going to v2 within months. Plus FileEmitter is `@experimental` — going to 1.0 either reneges on the experimental promise or locks the shape before consumers have stressed it.

**Recommended roadmap (Option A).**

```
0.3.0  →  BREAKING boost:init removal (current Unreleased)
0.4.0  →  Items #7 + #8 paired (manifest cleanup + vendor-namespaced slugs)
1.0.0  →  Lock FileEmitter shape; declare stability; all known breaking changes absorbed
```

This is the only path that produces a v1 that means what v1 should mean.

**Done when.** Roadmap decision recorded in `RELEASING.md` or CHANGELOG. Branch-aliases bumped to `0.3.x-dev` across all four repos. Consumer constraints stay `^1.0@dev` for now — they get reconciled to `^0.3` as part of item #3.

---

## 2. Tag boost-core's next release

**What.** Cut the release for the `## [Unreleased]` section in `boost-core/CHANGELOG.md`. Version depends on item #1: either `0.3.0` (Option A) or `1.0.0` (Option B).

**Contents already drafted.** BREAKING removal of `composer boost:init`; global-context auto-sync; basename collision detection; `BOOST_SKIP_GITIGNORE` env var.

**Files.** `boost-core/CHANGELOG.md` (move Unreleased → tagged section, add compare link). `boost-core/RELEASING.md` has publish steps.

**Done when.** Tag exists on GitHub, GitHub Release published, Packagist resolves it.

---

## 3. Tag the other three packages

**What.**
- `package-boost-php`: bump from `0.1.1`. To `0.3.0` (Option A) or `1.0.0` (Option B).
- `package-boost-laravel`: first tag ever.
- `project-boost`: first tag ever.

**Constraint update is part of tagging, not a follow-up.** Before each tag, edit `composer.json` to replace `sandermuller/boost-core: ^1.0@dev` with the post-#2 real constraint (`^0.3` under Option A). For `-laravel`, also bump `sandermuller/package-boost-php` to the same scheme. Tagging without this update produces a release that still only resolves via dev-main — useless to anyone pinning a version.

**Verify `package-boost-php` 0.1.0/0.1.1 aren't already broken.** boost-core's 0.1.0/0.1.1 shipped with a runtime-fatal `CommandProvider` bug (plain Symfony commands instead of `Composer\Command\BaseCommand`), fixed in 0.1.2 via `BaseCommandAdapter`. Package-boost-php has its own `PackageBoostPhpCommandProvider` at `src/PackageBoostPhpCommandProvider.php`. Inspect `getCommands()` — if it returns plain Symfony commands without an adapter wrap, the existing 0.1.0/0.1.1 tags are broken and `package-boost-php:lean` / `package-boost-php:gitattributes` fail on first invocation. If so, mark them superseded in the new tag's CHANGELOG entry.

**Coordination.** Tag in dep order: `boost-core` → `package-boost-php` → `package-boost-laravel`. `project-boost` is independent of the package-boost branch and can tag any time after `boost-core`.

**Done when.** Each repo has a tag. Each repo's `composer.json` `require` constraints on `boost-core` (and `package-boost-php` for the laravel variant) point at real version ranges, not `@dev`. `composer require sandermuller/<each-package>` resolves to a tag without `--prefer-source` or `minimum-stability: dev`.

---

## 4. Refresh consumer READMEs

Three repos have stale references. Each is a small commit but blocks the next-user "I tried this and it errored" loop.

**`package-boost-php/README.md`.**
- Line 19: `composer boost:init` — **removed in boost-core 0.3.0 BREAKING**. Drop the line. `boost:install` auto-inits now.
- Line 21: `vendor/bin/boost sync` ✓ (already correct).

**`package-boost-laravel/README.md`.**
- Lines 12–23: "Not yet on Packagist. While you wait, install via vcs repositories" callout. **Stale** — `package-boost-php` IS on Packagist (0.1.0/0.1.1). The laravel package will be too after #3. Drop the entire vcs-repo callout once #3 lands.
- Line 32: `composer boost:init` — drop, same reason as above.

**`project-boost/README.md`.**
- Line 16: `boost-core only ships dev-main on Packagist for now, no tagged releases yet` — **stale** (0.2.0 is tagged). Replace with the post-#1 constraint shape.
- Line 21: `composer boost:init` — drop, same reason.

**Done when.** All three READMEs reference only currently-existing commands and version-correct install instructions.

---

## 5. Retire `sandermuller/package-boost` v0.15

**What.** Pre-split legacy package, 13k installs on Packagist. Superseded by `package-boost-php` (framework-agnostic) + `package-boost-laravel` (Laravel-specific).

**Done when.**
- Legacy README points at the successors with a migration table (which features moved where).
- Final tag marks itself deprecated via `composer.json: "abandoned": "sandermuller/package-boost-php"` (the `abandoned` field is single-valued — string for one suggested replacement OR `true` for "abandoned with no replacement". Pick `-php` as the primary successor since framework-agnostic users are the majority; document the `-laravel` migration path in the README).
- Final entry in legacy CHANGELOG.

**Caveat.** Do NOT delete the repo or unpublish from Packagist — existing `composer.lock` files at 13k installs reference it. Deprecate + redirect only.

**Files.** All in `github.com/SanderMuller/package-boost` (separate repo, not in this tree).

---

## 6. Notify `repo-init` author about the new contracts

**What.** Two contracts moved into this family. Repo-init currently inlines them.

| Contract | New home | Reference file |
|---|---|---|
| Managed `.gitattributes` block with foreign-line preservation | `package-boost-php:gitattributes` command | `package-boost-php/src/Commands/GitattributesCommand.php` |
| User-scope skill install to `~/.{agent}/skills/<pkg>/` | `boost-core` `--scope=user` flag, **auto-triggered** under `composer global require` | `boost-core/src/BoostCorePlugin.php` (`isGlobalContext()` + `runGlobalSync()`) |

**Note.** Auto-triggering removes the need for `repo-init` to ship a post-install script. Significant simplification on their side.

**Done when.** Issue filed at `github.com/SanderMuller/repo-init` describing the new contract locations, with migration guidance. Author confirms or pushes back.

---

## 7. Manifest-based cleanup for `composer global remove` (boost-core)

**Why.** Stale user-scope skills accumulate forever today. CHANGELOG lists this as a known limitation: "delete by hand after removing the package."

**Approach (suggested).**
- On user-scope sync, write `~/.boost/manifests/<vendor-name>-<package>.json` listing every file emitted. Filename matches the slug from #8 (see coordination note below) so #7 + #8 use a single naming scheme from day one.
- Subscribe to `Composer\Installer\PackageEvents::PRE_PACKAGE_UNINSTALL` (or `POST_PACKAGE_UNINSTALL` — both work; PRE leaves the package files readable if cleanup fails, POST is the more conventional choice for "after-the-fact" cleanup).
- On uninstall: load manifest, delete listed files, delete manifest.
- Missing manifest = pre-feature install; warn and skip.

**Files.** `boost-core/src/BoostCorePlugin.php` (add subscriber); `boost-core/src/Sync/SyncEngine.php::syncUser()` (write manifest); new `boost-core/src/Sync/UserScopeManifest.php`.

**Done when.** Integration test: `composer global require <pkg>` → assert files appear → `composer global remove <pkg>` → assert files gone.

**Dependency.** Ship same release as #8 — both touch user-scope paths; one user migration is better than two. The manifest filename scheme **must** match #8's slug (`<vendor>-<package>.json`, not `<basename>.json`); shipping #7 with the old basename scheme means re-renaming every manifest file when #8 lands.

---

## 8. Vendor-namespaced slugs for user-scope paths (boost-core)

**Why.** Today `~/.{agent}/skills/<basename>/` namespaces by `basename($pkg)`, so `vendor-a/foo` + `vendor-b/foo` collide. Collisions are *detected* and warned today (`boost-core` commit `9d8354a`) but not resolved.

**Proposed slug.** `vendor-name/package` → `vendor-name-package` (replace `/` with `-`).

**Done when.**
- New installs write to `~/.{agent}/skills/<vendor>-<package>/`.
- One-time migration on first sync after the bump: detect old `~/.{agent}/skills/<basename>/` dirs that match an installed package, rename, log a one-line notice.
- Collision detection still in place but unreachable in practice.

**Files.** `boost-core/src/Sync/SyncEngine.php::packageSuffix()` + `boost-core/src/BoostCorePlugin.php::packageSuffix()` (duplicated today; consider extracting to a shared util). Migration logic in `runGlobalSync()`.

**Caveat.** Migration must be idempotent (running twice = no-op) and safe (don't delete unrecognized files).

---

## 9. Self-sync boost-core's own fan-out (boost-core)

**What.** Boost-core has 18 fan-out files committed (2 skills × 9 agents) because Composer plugins don't activate in their own dev env, so `onPostAutoloadDump()` never fires for boost-core itself.

**Now possible** because `vendor/bin/boost sync` works (shipped in 0.2.0).

**Proposed change.** In `boost-core/composer.json`:
```json
"scripts": {
  "post-install-cmd": "@php bin/boost sync",
  "post-update-cmd": "@php bin/boost sync"
}
```

Then `git rm -r` the fan-out dirs + root guideline files:

```
.agents .amp .claude .cursor .gemini .junie .kiro .opencode
.github/skills .github/copilot-instructions.md
AGENTS.md CLAUDE.md GEMINI.md
```

(Note: Codex writes to `.agents/skills`, not `.codex/skills` — there is no `.codex/` directory.)

**Trade-off.** Pro: 1-line diff per skill edit instead of 1×9. Con: GitHub browsers lose the documentation-by-example view. Coin-flip.

**Done when.** Clean checkout of boost-core has only source files; `composer install` regenerates the fan-out.

---

## 10. AST round-trip stability tests (boost-core)

**What.** `boost-core/src/Config/BoostConfigWriter.php` has documented limitations (header docblocks stripped; formatting may change). No tests pin these as the expected contract.

**Proposed test.** Parse → write-unchanged → parse-again → assert semantic equivalence (same agents, same vendors, same emitters) even if formatting drifts. Catches regressions if `printFormatPreserving` is wired later.

**Files.** New `boost-core/tests/Unit/Config/BoostConfigWriterRoundTripTest.php`.

**Lowest priority.** Won't block any release.

---

## Decisions inherited (don't relitigate)

- **`composer boost:init` removed** for next boost-core release. `boost:install` auto-inits. Don't re-add.
- **Skill output is always `<name>/SKILL.md`** regardless of source layout. Claude Code's discovery only finds the directory form. Flat outputs are silently ignored. Sync prunes obsolete flat siblings.
- **Two invocation surfaces, both supported.** Consumer READMEs use `vendor/bin/boost <cmd>` because it survives end-user (non-dev) installs where `composer/composer` is not in `vendor/`. Boost-core's own README still uses `composer boost:*` (its audience is tooling authors who already work inside Composer). Both paths reach the same `CommandRegistry`.
- **`BaseCommandAdapter` bridges Symfony commands → Composer's `CommandProvider` capability** at runtime via reflection. `CommandRegistry` holds plain Symfony commands. Don't "simplify" by making everything extend `Composer\Command\BaseCommand` — that re-breaks end-user installs.
- **`FileEmitter` plugin contract is `@experimental`.** Shape will change before v1.0 stable. Don't lock the seam by adding methods consumers will rely on. (See #1 — Option B forces a decision on this.)
- **9 agents, hardcoded list.** Claude Code, Cursor, Copilot, Codex, Gemini, Junie, Kiro, OpenCode, Amp. Adding an agent = new `*Target.php` + add to `SyncEngine::default()`. Don't make this dynamic.
- **Allowlist + first-party prefix matcher is the vendor trust mechanism.** See `boost-core/src/Discovery/FirstPartyPrefixes.php`. Don't replace with "trust everything" or "scan for marker file."
- **boost-core's own plugin doesn't activate in its own dev env** (Composer constraint). Workaround = item #9.
- **Family release order is `boost-core` → `package-boost-php` → `package-boost-laravel`.** `project-boost` is independent of the package-boost branch.

## Invocation model trade-offs (background for #1, #7, #9)

Three patterns existed when boost-core was designed. Worth understanding even if you don't reopen the choice — items #7 and #9 are direct consequences of the path picked.

| Pattern | Example | Auto-runs on `composer install`? | Per-package uninstall hook? | Survives end-user (non-dev) install? | Needs `allow-plugins`? |
|---|---|---|---|---|---|
| **A. Composer plugin** | `boost-core` (current) | Yes, via `POST_AUTOLOAD_DUMP` subscriber | Yes, via `PACKAGE_EVENTS::*_PACKAGE_UNINSTALL` | Yes (with `BaseCommandAdapter` split — see Decisions Inherited) | Yes, one-time `y/N` prompt |
| **B. Post-install-script** | Laravel Boost (via service provider, not strictly this pattern in a framework-agnostic context) | Only if user wires it into their own root `composer.json` scripts | No (root scripts get no per-package events) | Yes | No |
| **C. Pure `vendor/bin/<tool>`** | Pint, Rector, PHPStan, Psalm | No — user invokes manually or in CI | No | Yes | No |

### Pattern A — Composer plugin (boost-core's choice)

**Pros.**
- Auto-runs on every install/update without per-user setup.
- Can subscribe to `PRE_PACKAGE_UNINSTALL` / `POST_PACKAGE_UNINSTALL` — required for item #7's cleanup-on-remove.
- Can detect `composer global` context cleanly (item #6 / `isGlobalContext()` exists because of this).
- Commands discoverable via `composer list` (`composer boost:sync` etc.).
- Single `composer require --dev <pkg>` installs and activates.

**Cons.**
- Plugin doesn't activate in its own dev env (the source of item #9; boost-core can't auto-sync itself).
- Composer's `CommandProvider` capability runtime-checks `instanceof Composer\Command\BaseCommand` — pure Symfony commands rejected. Fixed via `BaseCommandAdapter` but cost real engineering time and shipped two broken tags (0.1.0/0.1.1) before getting it right.
- End-user installs that don't have `composer/composer` in `vendor/` fatal if the plugin loads Composer namespace transitively. Required the `CommandRegistry` + `BaseCommandAdapter` split.
- **Trust friction at install time** — the dominant real-world cost. See next subsection.

#### Trust friction — the specific cost of Pattern A

Composer 2.2+ requires every plugin to be explicitly allowlisted in the root project's `composer.json` under `config.allow-plugins`. The first `composer require --dev sandermuller/boost-core` produces:

```
sandermuller/boost-core contains a Composer plugin which is currently not in your allow-plugins config.
See https://getcomposer.org/allow-plugins
Do you trust "sandermuller/boost-core" to execute code and wish to enable it now? (writes "allow-plugins" to composer.json) [y,n,d,?]
```

Concrete pain points this causes:

- **"Unknown what gets executed."** The prompt asks the user to trust arbitrary code execution. There's no machine-readable manifest of what the plugin does. Auditing requires reading the source — `BoostCorePlugin.php` and every event subscriber it registers. Most users click through without auditing; security-aware users (or audit policies) treat this as a real cost.
- **Scope of what actually runs on `composer install`.** For boost-core specifically: reads `.ai/`, reads `boost.php` (eval'd as PHP), walks `vendor/`, instantiates and calls `FileEmitter` classes from allowlisted vendor packages (third-party code execution through the FileEmitter contract), writes to `.gitignore` + 9 agent directories + root guideline files (`CLAUDE.md`, `AGENTS.md`, `GEMINI.md`, `.github/copilot-instructions.md`). In `composer global` context: also writes to `$HOME/.{agent}/skills/<pkg>/`. Every `composer install`, every `composer update`.
- **CI brittleness.** CI environments running `composer install --no-interaction` can't answer the prompt. If `allow-plugins` is not pre-configured, Composer silently skips the plugin — auto-sync never runs, CI may green-light a broken state. Solution: every consumer must pre-configure `"config": { "allow-plugins": { "sandermuller/boost-core": true } }` in their root `composer.json`. That's an undocumented requirement for the auto-sync feature.
- **No re-prompt on update.** Once allowlisted, the plugin can change what it does in future versions without re-prompting. A 1.0 → 1.1 minor that adds new filesystem writes runs silently. There's no diff-on-update mechanism.
- **Corporate policy blast radius.** Some organizations ban Composer plugins entirely as security policy. Such consumers cannot use the plugin path at all — they can only consume `vendor/bin/boost` invoked explicitly from their own scripts.
- **Renovate / Dependabot risk classification.** Some automated dependency-update tools flag composer-plugin packages as elevated-risk relative to plain libraries, because the install-time code execution surface widens.

Pattern C (pure `vendor/bin/<tool>`) avoids **all** of the above. Nothing runs until the user explicitly invokes the binary. The tradeoff is losing auto-sync and `PACKAGE_UNINSTALL` events.

### Pattern B — Post-install-script (Laravel Boost-style, adapted to framework-agnostic)

**Pros.**
- No `allow-plugins` prompt.
- User explicitly opts into auto-run by editing their own root `composer.json` scripts — clean security model.
- No fight with Composer's plugin lifecycle / type strictness / capability validation.
- Trivial debugging — it's just a Composer script line.

**Cons.**
- **Per-user manual wiring.** Each consumer must add `"post-install-cmd": "@php vendor/bin/boost sync"` to their root `composer.json`. Most won't. "Auto-sync on install" becomes "auto-sync if you remembered to wire it."
- **No per-package event hooks.** Root composer.json scripts don't receive `PACKAGE_UNINSTALL` events. Item #7 (cleanup on remove) becomes architecturally impossible — would need a global watcher or accept "no auto-cleanup."
- **Note on Laravel Boost specifically:** it uses Laravel's service-provider auto-discovery (`extra.laravel.providers`), not raw Composer scripts. That pattern doesn't generalize outside Laravel. A framework-agnostic equivalent really is "ask the user to edit their composer.json."

### Pattern C — Pure `vendor/bin/<tool>` (Pint / Rector / PHPStan / Psalm)

**Pros.**
- Maximum simplicity. Zero Composer machinery to fight.
- Familiar pattern — every PHP dev knows what `vendor/bin/pint` does.
- No `allow-plugins` prompt.
- Identical behavior in dev-installed and end-user environments.
- Trivial to test (just exec the binary).
- Trivial to compose with other tools in CI scripts.

**Cons.**
- **No auto-sync on install.** User runs `vendor/bin/boost sync` manually or wires it into CI. Skills emitted from new vendor packages won't appear until they re-run.
- **Discovery is worse.** `composer list` won't show boost commands. README onboarding has to teach the binary path.
- **No cleanup on remove.** Same architectural limit as Pattern B.

### What boost-core does today

**Hybrid: Pattern A + Pattern C in one package.** The plugin handles auto-sync and discovery; `vendor/bin/boost <cmd>` is the documented invocation in consumer READMEs. Both paths reach the same `CommandRegistry`. The split is documented under Decisions Inherited (two invocation surfaces).

This hybrid gives:
- Auto-sync (Pattern A win)
- Per-package uninstall events (Pattern A win — needed for item #7)
- `vendor/bin/boost` works in end-user installs (Pattern C win)
- One install command (Pattern A win)

Cost paid: `BaseCommandAdapter` complexity, the `allow-plugins` prompt, item #9 (can't self-sync).

### When you'd reconsider

- **If item #7 is dropped permanently** (accept "no auto-cleanup on remove"), Pattern C alone becomes viable. The plugin layer's main load-bearing job goes away.
- **If trust friction matters more than auto-sync** — security-conscious teams, corporate dep policies, CI brittleness from missing `allow-plugins` config — switch to Pattern C plus a README instruction "run `vendor/bin/boost sync` after install." This is the path Pint / Rector / PHPStan / Psalm all picked, and it's a known-quantity user experience.
- **If both auto-sync AND uninstall hooks are non-negotiable**, you're locked into Pattern A. The trust friction, `BaseCommandAdapter` complexity, and item #9 (no self-sync) are the price. There's no shortcut.

The actual call worth revisiting once items #5–#10 are done: is the auto-sync convenience worth the install-time trust prompt for boost-core's audience? Pint / Rector / Psalm answered "no" for theirs. Boost-core has answered "yes, with hybrid Pattern C as the escape hatch." Both answers are defensible; the latter is what's currently shipped.

## Quick refs (boost-core)

| Need to | Read |
|---|---|
| Understand the sync pipeline | `src/Sync/SyncEngine.php` (top docblock) |
| Add a new agent | `src/Agents/AgentTarget.php` + `ClaudeCodeTarget.php` for shape |
| Touch the plugin | `src/BoostCorePlugin.php` |
| Touch the standalone bin | `bin/boost` + `src/Commands/CommandRegistry.php` |
| Touch the gitignore behavior | `src/Sync/GitignoreManager.php` |
| Write tests against a real composer subprocess | `tests/Integration/PluginCommandSurfaceTest.php` (template) |
| Understand the env var surface | `src/Env.php` (centralized registry) |
| See what's released | `CHANGELOG.md` (Keep a Changelog format) |
| Cut a release | `RELEASING.md` |

## Test gauntlet (run in each repo before tagging)

```bash
composer phpstan       # PHPStan max + strict-rules
composer format        # Pint
composer test          # Pest unit + integration (real composer subprocess tests in boost-core)
composer validate-gitattributes
```

Or all at once: `composer qa`.

## Out of scope (don't expand this doc)

- New first-party packages (the 4 are the family; growth happens via vendor plugins shipping `FileEmitter`s — see Decisions Inherited for the agent-list policy).
- Format-preserving config-writer printing (post-v1).
- Web UI, telemetry, analytics, license enforcement — none of these are planned.
