# Releasing

## Version-stream policy (recorded 2026-05-18)

The `-boost` family stays on the **0.x line** until the planned breaking changes are absorbed. Roadmap:

```
0.3.x  →  patches + additive features (current)
0.4.0  →  manifest-based cleanup-on-remove + vendor-namespaced user-scope slugs (paired BREAKING)
1.0.0  →  FileEmitter shape locked + @experimental tag stripped + stable contract declared
```

**Branch-alias convention.** Every repo's `extra.branch-alias.dev-main` matches the next planned minor's `<minor>.x-dev`. While shipping 0.3.x patches the alias is `0.3.x-dev`; the moment 0.4.0 is cut the alias bumps to `0.4.x-dev`. Consumers pinning `sandermuller/<pkg>: ^0.3` resolve to tagged 0.3.x releases via Packagist; consumers pinning `^0.3.0@dev` get dev-main via the alias.

**Why not jump to 1.0 now.** Items in the roadmap above are user-visible breaking changes. Cutting 1.0 today forces 2.0 within months when those land — burns the credibility v1 is supposed to signal. Plus the `FileEmitter` contract is still `@experimental`; freezing it before a second consumer has stressed the seam locks in the wrong shape.

**Consumer constraint shape.** Every consumer (`package-boost-php`, `package-boost-laravel`, `project-boost`) pins `sandermuller/boost-core: ^0.3` (NOT `^1.0@dev`). The `^1.0@dev` pattern resolved only via dev-main + the old `1.x-dev` alias and never reached tagged releases — broken-by-design for any consumer trying to pin a stable version.

**Constraint floors must match feature usage.** `^0.3` resolves to any 0.3.x tag (0.3.0, 0.3.1, …). If a consumer uses a feature introduced mid-minor — e.g. `BoostAutoSync::run` (shipped in 0.3.1) or `BoostAutoSync::runWithSummary` (shipped in 0.3.2) — the constraint floor must be bumped to that exact patch (`^0.3.1`, `^0.3.2`) so a consumer's lockfile can't resolve to a too-old patch that lacks the referenced class/method. Don't pin to `^0.3` if you reference a feature that wasn't in 0.3.0.

---

The `-boost` family is four packages with a strict dependency order:

```
boost-core
   ↑
   ├── package-boost-php
   │       ↑
   │       └── package-boost-laravel
   │
   └── project-boost
```

A coordinated release tags + publishes in this order. Skipping ahead breaks
downstream resolution.

---

## Pre-flight (any release)

Run from the repo being released:

```bash
composer ci              # tests + phpstan + pint
git status --porcelain   # must be empty
git log --oneline -5     # confirm the commits you expect
```

Also: the dependent packages' `composer.json` files have committed
`repositories: [{type: path, ...}]` entries for sibling-repo development.
**These must be removed before the first Packagist release.**

---

## First publish (one-time, in dep order)

### 1. boost-core

```bash
cd ~/Documents/GitHub/boost-core

# Tag the alpha
git tag v1.0.0-alpha.1
git push origin v1.0.0-alpha.1
```

Then on Packagist:
1. Go to https://packagist.org/packages/submit
2. Submit `https://github.com/sandermuller/boost-core`
3. Confirm the package detail page shows `v1.0.0-alpha.1`

Set up the GitHub → Packagist webhook so future pushes auto-update:
- Packagist → your profile → Show API token
- GitHub repo → Settings → Webhooks → Add webhook
  - Payload URL: `https://packagist.org/api/github?username=sandermuller&apiToken=...`
  - Content type: `application/json`
  - Events: `Just the push event`

### 2. package-boost-php

```bash
cd ~/Documents/GitHub/package-boost-php
```

Edit `composer.json`:
- **Remove** the `repositories: [{type: path, ...}]` block entirely.
- **Change** `"sandermuller/boost-core": "^1.0@dev"` to `"sandermuller/boost-core": "^1.0.0-alpha.1"`.
- **Add** `"minimum-stability": "alpha", "prefer-stable": true` if not already alpha-friendly.

```bash
composer update sandermuller/boost-core   # confirms it resolves
composer ci                                # full QA against the Packagist version
git add -A && git commit -m "Switch sandermuller/boost-core to Packagist constraint"
git push
git tag v1.0.0-alpha.1
git push origin v1.0.0-alpha.1
```

Submit to Packagist + webhook same as above.

### 3. package-boost-laravel + project-boost (parallel)

Same pattern. For `package-boost-laravel`, both inter-package deps need updating:

```json
{
    "require": {
        "sandermuller/boost-core": "^1.0.0-alpha.1",
        "sandermuller/package-boost-php": "^1.0.0-alpha.1"
    }
}
```

Remove path repo block, commit, tag, push, submit, webhook.

`project-boost` only depends on `boost-core` — simpler edit.

---

## Subsequent releases

Once webhooks are set up:

```bash
# Coordinated bump (all four to next version)
for d in boost-core package-boost-php package-boost-laravel project-boost; do
  cd ~/Documents/GitHub/$d
  git tag v1.0.0-alpha.2
  git push origin v1.0.0-alpha.2
done
```

The webhooks auto-publish to Packagist.

If only one package changed, only that one needs a tag. Downstream packages
don't need a re-tag unless their constraints need bumping.

---

## When promoting to stable v1.0.0

1. Verify FileEmitter contract has a second non-trivial consumer (architect
   round-3 unlock condition). Currently only `McpJsonEmitter` exists; the
   contract is still `@experimental`.
2. Strip the `@experimental` tag from `boost-core/src/Contracts/FileEmitter.php`.
3. Coordinate tag v1.0.0 across all four repos.
4. Drop `minimum-stability: alpha` from dependent packages.

---

## What never goes in a tag

- `composer.lock` — gitignored, never tagged. Libraries don't pin lockfiles.
- `vendor/` — same.
- `repositories: [{type: path, ...}]` — must be absent from any tagged version.

---

## Rolling back

If a tag goes out wrong:

```bash
git tag -d v1.0.0-alpha.X                # delete locally
git push origin :refs/tags/v1.0.0-alpha.X  # delete remotely
```

Packagist treats a deleted tag as deleted. Fix and retag.

**Never** force-push a published tag — even if you delete + recreate locally,
Composer's cache can hold the old SHA. Always bump to the next alpha number
instead.
