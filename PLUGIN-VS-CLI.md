# Plugin vs CLI: design decision

Open question: should boost-core stay a Composer plugin, or become a pure CLI tool (rector/pint pattern)?

This doc lays out the trade-offs so a decision can be made deliberately. Not a release artefact — for maintainer use. Move to `internal/` or delete after the call.

## Today's shape

- `composer.json` type: `composer-plugin`
- Plugin-coupled code: **315 LOC** across three files
    - `src/BoostCorePlugin.php` (219 LOC) — implements `PluginInterface` + `EventSubscriberInterface` + `Capable`
    - `src/BoostCoreCommandProvider.php` (38 LOC) — `Composer\Plugin\Capability\CommandProvider` impl
    - `src/Commands/BaseCommandAdapter.php` (58 LOC) — wraps Symfony commands as `Composer\Command\BaseCommand` for the capability path
- Three surfaces:
    1. **CommandProvider capability** → `composer boost:install`, `composer boost:sync`, `composer boost:scan`, `composer boost:doctor`, `composer boost:new`
    2. **`onPostAutoloadDump` event subscriber** → auto-sync after every `composer install` / `composer update`, plus a global-context branch that fans skill packages into `~/.{agent}/skills/` on `composer global require`
    3. **Standalone `bin/boost`** → shares `CommandRegistry` with the plugin; works in end-user installs without `composer/composer` in vendor/

End users add the package to `require-dev`, accept the Composer `allow-plugins` prompt once, and never think about it again — assuming they trust the prompt.

> Boost-core's own `composer.json` ships a `post-install-cmd` script that ALSO runs `vendor/bin/boost sync` — the maintainer's existing belt-and-braces against plugin-hook failure. That script and the plugin's `onPostAutoloadDump` currently double-fire on boost-core's dev tree. Removing one of them is the question that triggered this doc.

## Reference shapes

- **rector/pint**: composer type `library`, only `bin/<name>`. Users add `vendor/bin/<name>` to their own composer scripts for auto-formatting/refactoring.
- **phpstan**: same, plus an optional `phpstan/extension-installer` plugin for auto-wiring extensions (so the precedent goes both ways).
- **laravel/boost** *(per maintainer recall — not verified for this doc)*: tells users to add a `post-install-cmd` / `post-update-cmd` script invoking `boost update`. Suggests laravel/boost itself is library-shape (no plugin), or at least doesn't rely on a plugin hook for the auto-sync.
- **composer/composer itself, wikimedia/composer-merge-plugin**: plugin patterns; demonstrate the API is stable and trusted upstream.

The PHP community is comfortable with both shapes. Plugin isn't inherently distrusted — it's a different distribution model with different trade-offs.

## What does NOT change either way

These stay regardless of which option you pick:

- `Composer\InstalledVersions` usage in `src/Sync/InstalledPackages.php` — that's `composer-runtime-api` (always present, lives in every composer-managed project's autoload), distinct from the `composer/composer` package itself.
- `bin/boost` standalone path + the shared `CommandRegistry`.
- All non-plugin code (`SyncEngine`, `AgentTarget` family, `SkillLoader`, `BoostConfig`, emitters, gitignore manager, etc.) — the bulk of the codebase.
- The `composer test` / `composer phpstan` / `composer rector` / `composer qa` scripts.
- `.ai/` source layout, sync semantics, dir-form `<name>/SKILL.md` output.

## Trade-off dimensions

### 1. Auto-sync convenience (project-scope)

| | Plugin | CLI |
|---|---|---|
| User action on first install | none | add 1 composer.json line |
| User action on subsequent installs | none | none (script runs automatically) |
| User-visible behaviour after the one-time setup | identical | identical |

Magnitude: one composer.json edit at install time, comparable to a config-publish step. Not a major UX downgrade — laravel/boost apparently accepts this pattern.

### 2. Global-context auto-sync — the irreplaceable bit

`composer global require sandermuller/repo-init` today fans the package's skills into `~/.{agent}/skills/repo-init/SKILL.md` automatically. Shipped 2026-05-18 in 0.2.0; validated end-to-end by `repo-init` and `package-boost-laravel` consumers.

Without a plugin, this becomes a manual step: users run `~/.composer/vendor/bin/boost sync --scope=user` after every global install. Most won't, until they hit "huh, the skill isn't showing up in Claude Code" and have to debug.

**This is the only feature that cannot be replicated in a CLI-only shape.** Adding the manual step is a real UX downgrade for tooling-package authors who pitch their package as "composer global require and you're done."

Two complications worth knowing:

- The `allow-plugins` prompt for global context lives in `~/.composer/composer.json`. Users globally requiring a package that ships boost-core transitively get the prompt globally — friction that hits new users hardest.
- The feature is one day old. Rolling it back is itself a (small) breaking change for any consumer that integrated since 0.2.0. Bundle packages (`project-boost`, `package-boost-php`, `package-boost-laravel`) — uncertain whether any rely on it yet.

### 3. Trust friction

**Plugin:** Composer's `allow-plugins` prompt fires once per package per project (or globally for `composer global`). User says yes once. Plugin reading is 219 + 38 + 58 = 315 LOC, all auditable.

**CLI:** No prompt. Zero friction.

How bad is the friction in practice? **Unknown — this is the maintainer's instinct, not a reported issue.** Active dogfooders (peers `155ipb4i`, `9cpb3igv`, `8nwkhqnn`, `widsc2lv`) are using the plugin shape without complaint. The hypothesis "people might not trust it" is plausible (allow-plugins prompts ARE annoying, and a plugin that mutates filesystem on every composer op is in scope-creep territory) but unvalidated.

Caveat: trust friction may be HIGHER for global context (point 2's complication) — `composer global require` users may not expect a transitively-loaded plugin to start writing to `~/`.

### 4. Bug surface

Every plugin-territory bug shipped this session:

| Bug | Code location | Survives in option B? |
|---|---|---|
| `CommandProvider` capability validation | `BoostCoreCommandProvider` + new `BaseCommandAdapter` | yes (CommandProvider stays) |
| Adapter dispatch swallowing Composer global flags | `BaseCommandAdapter::execute` | yes |
| Branch-alias `1.x-dev` mismatch | `composer.json` | yes (unrelated to plugin) |
| Prune-on-failure ordering | `SyncEngine::fanOut` / `syncUser` | n/a — non-plugin code |
| Double-nested user-scope paths | `SyncEngine::rewriteForUserScope` | n/a — non-plugin code |

So the "5 fix cycles in one day" framing is misleading: 2 are plugin-intrinsic (would not exist in option A, would still exist in option B since CommandProvider stays), 1 is composer.json metadata (unrelated to plugin status), 2 are in the shared sync engine (would exist in any shape).

Real bug-surface delta:
- **Option A → B**: removes ~2 bugs' worth of code paths (the `onPostAutoloadDump` event subscriber). Keeps CommandProvider validation + adapter dispatch as a maintenance cost.
- **Option A → A (pure CLI)**: removes the CommandProvider path too. ~all 315 plugin LOC gone + 2 subprocess tests retired.

### 5. Discoverability

**Plugin:** `composer list` enumerates `boost:*` commands inside any project that has boost-core installed. Users see them without needing to look at `vendor/bin/`. Tab-completion works in composer-aware shells.

**CLI:** users have to know to run `vendor/bin/boost`. Some don't know `vendor/bin/` exists; many shells don't tab-complete it. The composer-script alias pattern (`composer sync-ai` etc. — already exists in boost-core's own composer.json) is the standard mitigation, but it's per-project setup.

Real but small downgrade.

### 6. Testing complexity

The plugin paths are tested via real `composer install` subprocesses spawning a tmp fixture project. Three integration tests do this:

- `tests/Integration/PluginCommandSurfaceTest.php` (~12s per run)
- `tests/Integration/EndUserInstallTest.php` (~20s per run)
- `tests/Integration/GlobalContextAutosyncTest.php` (~30s × 2 tests)

Total: ~92s of subprocess test time per suite run, concentrated entirely in plugin-coupled paths. Removing the plugin (option A) lets these tests retire — they exist only to gate plugin behaviour Composer wouldn't otherwise let us exercise without a real composer process.

CLI-only: all behaviour testable without subprocess. Suite gets ~50% faster.

### 7. Bundle-package blast radius

`project-boost`, `package-boost-php`, `package-boost-laravel`, `repo-init` all document `composer boost:*` commands in their READMEs / install instructions / integration tests. The boost-* family is currently coordinated around the `composer boost:*` surface.

- **Option A (pure CLI)**: removes `composer boost:*` entirely. Every bundle package needs README/scripts/CI updates. 4 downstream repos to coordinate. Probably 1.0.0.
- **Option B (drop auto-sync, keep CommandProvider)**: `composer boost:*` still works. Bundle packages need to document the `post-install-cmd` opt-in instead of relying on auto-sync. Probably 0.4.0.
- **Option C / D**: no downstream change required.

### 8. Migration runway

For option B (drop auto-sync): could ship 0.4.0 with the plugin's `onPostAutoloadDump` still wired but emitting a deprecation notice (`boost: auto-sync hook is deprecated, add 'vendor/bin/boost sync' to your post-install-cmd by 0.5.0`). Run a release cycle with the warning. Then 0.5.0 actually deletes the hook. Consumers have visible runway.

For option A (pure CLI): harder to soften. The `composer-plugin` type is binary — either the plugin loads or it doesn't. Could ship 0.4.0 with both code paths and the README pointing at CLI, then 1.0.0 deletes the plugin. Two-step migration over two minors.

## Options

### Option A — Pure CLI (rector pattern)

| | |
|---|---|
| Composer type | `library` |
| Code deleted | `BoostCorePlugin` (219 LOC), `BoostCoreCommandProvider` (38 LOC), `BaseCommandAdapter` (58 LOC), three subprocess integration tests |
| Code added | None |
| `composer boost:*` commands | gone |
| Project auto-sync | user opt-in via own `post-install-cmd` |
| Global-context auto-sync | gone; users run `vendor/bin/boost sync --scope=user` manually |
| Downstream changes | every bundle package: README + scripts + CI |
| Version impact | 1.0.0 (or 0.4.0 if you accept naming a major breaking change as a minor pre-1.0) |
| Suite runtime | ~50% faster (drops the 3 subprocess tests) |

### Option B — Keep plugin, drop auto-sync only

| | |
|---|---|
| Composer type | `composer-plugin` (unchanged) |
| Code deleted | `BoostCorePlugin::onPostAutoloadDump` + global-ctx detection + helpers (~150 LOC of the 219 in BoostCorePlugin) |
| Code kept | `BoostCoreCommandProvider`, `BaseCommandAdapter`, plugin shell |
| `composer boost:*` commands | still work |
| Project auto-sync | user opt-in via own `post-install-cmd` |
| Global-context auto-sync | gone (same as A) |
| Downstream changes | README/docs updates: stop documenting auto-sync, document the opt-in script |
| Version impact | 0.4.0 (breaking but smaller than A); migration runway available via deprecation warning |
| Suite runtime | slight reduction (GlobalContextAutosyncTest retires) |

### Option C — Status-quo minus the redundant script

| | |
|---|---|
| Composer type | `composer-plugin` (unchanged) |
| Code deleted | One block in boost-core's own `composer.json` (`post-install-cmd`) |
| `composer boost:*` commands | unchanged |
| Project auto-sync | unchanged (plugin hook stays canonical) |
| Global-context auto-sync | unchanged |
| Downstream changes | none |
| Version impact | patch (0.3.1) |
| Suite runtime | unchanged |

The original question that triggered this doc. Resolves the duplicate-firing in boost-core's own dev tree. Doesn't address trust friction at all.

### Option D — Docs-only repositioning (CLI-first messaging)

| | |
|---|---|
| Composer type | `composer-plugin` (unchanged) |
| Code deleted | nothing |
| `composer boost:*` commands | still work |
| Project auto-sync | still works |
| Global-context auto-sync | still works |
| Downstream changes | README emphasises `vendor/bin/boost`; mentions plugin as optional convenience |
| Version impact | none |
| Suite runtime | unchanged |

Effectively a hedge — keep both audiences, signal a preference. Cost: keep maintaining all the plugin code. Doesn't reduce surface, doesn't validate the trust hypothesis, just shifts framing.

## Recommendation

**Without user/peer evidence that trust friction is hurting adoption, the deciding question is: how much do you value the global-context auto-sync feature you shipped today?**

- If global-ctx auto-sync is load-bearing for the bundle packages' value prop → **D + C** (ship C now, defer the bigger call until you see real friction signal).
- If global-ctx auto-sync is "nice to have, not load-bearing" → **B**, with a 0.4.0 → 0.5.0 deprecation runway. Bug surface drops, command UX stays.
- If you want to commit to ecosystem-norm shape and accept the global-ctx UX loss → **A**, but probably wait until 1.0.0 and do it once with downstream coordination.
- **C is the right shipped patch today either way** — the duplicate-firing script should go regardless of which longer-term direction you pick.

My (claude's) honest read: B is the under-discussed sweet spot IF you're willing to give up the global-ctx feature. The bug-surface reduction is smaller than I implied in the first draft of this doc (2 of the 5 fix cycles would still exist in B), but the conceptual surface reduction is real — auto-sync mutating files on every composer op is a behaviour that arguably should be opted into. A is defensible too but rolls back a real UX feature; do it once, not twice.

## Open questions for the maintainer

1. Is "users may not trust it" a hypothesis or have you heard it from a specific user / peer / Packagist comment?
2. Are any bundle packages (or downstream consumers) depending on global-context auto-sync today, or is it still "demonstrated and validated" without being in anyone's production flow?
3. What's the timeline appetite — 0.3.x stability for a while, or shipping 0.4.0 / 1.0.0 in the next week?
4. If picking B or A, do you want a deprecation runway (warning in 0.4.0, removal in 0.5.0), or hard cutover?
