# Releasing

Canonical release process for boost-core. Most of the operational workflow lives in the **`pre-release` skill** (`.claude/skills/pre-release/SKILL.md`) — this doc covers version-stream policy + family-coordination patterns + cross-package coordination rules. For step-by-step ship mechanics, invoke the skill.

## Version-stream policy

The `-boost` family stays on the **0.x line** until the planned breaking changes settle. Current roadmap:

```
0.9.x  →  patches + additive features (current — engine surface stabilizing)
0.10.x →  noise pass + SyncResult::renderAll helper + summary.clean tightening
1.0.0  →  FileEmitter shape locked + @experimental tags stripped + stable contract declared
```

**Branch-alias convention.** Every repo's `extra.branch-alias.dev-main` matches the next planned minor's `<minor>.x-dev`. While shipping 0.9.x patches the alias is `0.9.x-dev`; the moment 0.10.0 is cut the alias bumps to `0.10.x-dev`. Consumers pinning `sandermuller/<pkg>: ^0.9` resolve to tagged 0.9.x releases via Packagist; consumers pinning `^0.9.0@dev` get dev-main via the alias.

**Why not jump to 1.0 now.** Items in the roadmap above are user-visible behaviour changes. Cutting 1.0 today forces 2.0 within months when those land — burns the credibility v1 is supposed to signal. Plus the `FileEmitter` contract is still `@experimental`; freezing it before a second consumer has stressed the seam locks in the wrong shape.

**Consumer constraint shape.** Every consumer (`package-boost-php`, `package-boost-laravel`, `project-boost`) pins `sandermuller/boost-core: ^0.9` (NOT `^1.0@dev`). The `^1.0@dev` pattern resolved only via dev-main + the old `1.x-dev` alias and never reached tagged releases — broken-by-design for any consumer trying to pin a stable version.

**Constraint floors must match feature usage.** `^0.9` resolves to any 0.9.x tag (0.9.0, 0.9.1, …). If a consumer uses a feature introduced mid-minor — e.g. `withConventions()` (shipped in 0.9.0) — the floor must be bumped to that exact patch (`^0.9.0`) so a consumer's lockfile can't resolve to a too-old patch that lacks the referenced API.

## Floor-bump discipline (load-bearing-only)

When a downstream package (catalog, wrapper, consumer-app) considers floor-bumping its `boost-core` constraint after a patch ships:

> **Floor-bumps pin to the load-bearing fix only. Polish-tier patches in the same minor line ride along via the existing range constraint. Pin tighter only when the polish-tier patch becomes load-bearing for a specific downstream-feature.**

Audit heuristic: *is the new patch making something this package depends on actually work?* If yes → bump floor. If no → range constraint already resolves to latest on `composer update`; no constraint-driven churn for consumers.

**Example from the 0.9.x cycle:**
- 0.9.3 (render-fail data-loss safety) → load-bearing. Downstream packages bumped floor.
- 0.9.4 (diagnostic-visibility short-circuit) → polish. Downstream packages stayed at `^0.9.3` floor; consumers got 0.9.4 transitively via `composer update`.

The rule generalizes across every link in the family chain: engine→catalog, catalog→family-package, family-package→consumer-app. Each link applies the same triage.

**Failure mode**: a polish-tier patch that LATER reveals itself as load-bearing (e.g., UX patch was actually hiding a workflow regression). Mitigation: empirical-validation cycle (proving-consumer feedback on stable) surfaces those before broad adoption. Same safety-net as the patch's own pre-tag review.

This rule lives canonically in the boost-skills strategy doc; the version above is the engine-author-facing summary. Cross-reference: see boost-skills `internal/strategy.md` (or equivalent strategy-doc location).

## Pre-release process

Canonical workflow lives in the `pre-release` skill. Quick reference:

```
1. Rector → 2. Pint → 3. Pest → 4. PHPStan → 5. Docs audit
→ commit + push
→ 6. CI green-light gate
→ 7. Release notes (only after step 6 green)
→ user cuts tag
→ 8a. Pre-tag gate (HEAD-drift + notes-SHA-pin + CI-still-green)
→ 8b. Post-tag watch (tag-ref re-fire + release-event decorators)
```

The skill enforces:
- Release-notes-file blocked until CI green on the pushed SHA (prevents claiming "tests pass on CI matrix" before CI has actually run).
- `<!-- verified-sha: ... -->` HTML comment pinned at the top of every release-notes file (greppable from step 8a, invisible in GitHub release body).
- Step 8a re-verifies live `git ls-remote origin` (not cached `origin/main`) just before `gh release create` — catches concurrent pushes that slipped through between steps 7 and 8a.
- Step 8b waits for tag-ref re-fired workflows AND `on: release` decorators (e.g., `update-changelog.yml`) — neither is part of the pre-tag gate.

User cuts the tag, not the agent. The skill's job is the pre-tag gauntlet + post-tag watch — tagging itself is the operator's irreversible decision.

## Family-release-sequencing protocol

When boost-core ships a minor that downstream packages constrain against, the chain unblocks in dep order:

```
boost-core (foundational)
   ↓ tags first
package-boost-php (direct dependent, widens constraint)
   ↓ tags second
package-boost-laravel + project-boost (sub-dependent, widens constraint)
   ↓ tag third
consumer apps (hihaho, mijntp) absorb via composer update
```

**Widened-OR over hard-bump.** Direct dependents widening to `^0.8 || ^0.9` (vs. hard-bumping to `^0.9`) lets consumers on either major-shape continue to absorb the direct-dependent's patches. The pattern's load-bearing case: a downstream consumer pinning `boost-core ^0.8` shouldn't be blocked from absorbing a direct-dependent's bug fix just because the direct-dependent also gained `^0.9` support.

**Recovery branches** (when synchronous peer attention isn't available):
- **(A) Downstream waits**: default. Cheap, predictable.
- **(B) Temp repo-override escape hatch**: when the direct-dependent peer is unreachable for >4-6 hours (default threshold; tune per-cycle). Consumer adds a temp `repositories: [{type: path, ...}]` entry pointing at a local widened fork. Documented as escape hatch only; the path entry must come out before consumer's release tags.

Full protocol spec lives in the boost-skills strategy doc (cross-reference required for canonical version).

## Dependency order

The family is four packages with a strict dependency order:

```
boost-core
   ↑
   ├── package-boost-php
   │       ↑
   │       └── package-boost-laravel
   │
   └── project-boost
```

A coordinated release respects this order. Skipping ahead breaks downstream resolution.

## What never goes in a tag

- `composer.lock` — gitignored, never tagged. Libraries don't pin lockfiles.
- `vendor/` — same.
- `repositories: [{type: path, ...}]` — must be absent from any tagged version.
- `internal/` — gitignored, branch-local working notes (specs, release-notes drafts, working-memory). Never reaches consumers.

## Promoting to stable v1.0.0

When the criteria below are all met:

1. `FileEmitter` contract has a second non-trivial consumer (the lock-in criterion). Currently only `McpJsonEmitter` exists; the contract is still `@experimental`.
2. `SkillRenderer` contract has been stress-tested by a non-Blade renderer (cross-domain validation). `BladeRenderer` is the only consumer today.
3. Schema-vocabulary in `ConventionsSchema` is at v2 (or v1 explicitly locked); the slot-set and `pr.gates` discriminator branches have been exercised by at least one real consumer across each branch.
4. Family-release-sequencing protocol has gone through at least one major-bump cycle (not just minor cycles).

When the criteria land:

1. Strip the `@experimental` tag from `boost-core/src/Contracts/FileEmitter.php` and `src/Contracts/SkillRenderer.php`.
2. Coordinate tag v1.0.0 across all four repos.
3. Drop `minimum-stability: alpha` from dependent packages.

## Rolling back

If a tag goes out wrong:

```bash
git tag -d v0.X.Y                # delete locally
git push origin :refs/tags/v0.X.Y  # delete remotely
```

Packagist treats a deleted tag as deleted. Fix and retag with the next patch number — never reuse the same tag name.

**Never** force-push a published tag — even if you delete + recreate locally, Composer's cache can hold the old SHA. Always bump to the next patch number instead.

## Process maturity notes

The release process has accreted across the 0.7.x → 0.9.x cycles. Cross-cycle audit lessons that earn their place stay in:

- The `pre-release` skill (operational workflow)
- The boost-skills strategy doc (family-coordination patterns, codified rules)
- This doc (engine-author-facing version-stream policy + cross-references)

When a codified pattern reaches `N` cycles without producing a different decision than the no-frame baseline, prune it. Defer the prune evaluation until 3-5 cycles of empirical data are available.
