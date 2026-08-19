# Versioning and stability

boost-core follows [Semantic Versioning](https://semver.org). The promise covers
the public surface only:

- the config authoring API (`BoostConfig::configure()` and its `with*()` chain);
- the CLI — command names, documented options, and exit codes;
- the `BoostAutoSync` Composer hooks;
- the plugin contracts, such as `SkillRenderer`.

Everything marked `@internal` — which is the whole engine — and all on-disk
regenerable state may change in any release.

[`PUBLIC_API.md`](https://github.com/SanderMuller/boost-core/blob/main/PUBLIC_API.md)
enumerates the committed surface in full. From `1.0.0` on, a breaking change
lands only in a major bump and is called out in
[`CHANGELOG.md`](https://github.com/SanderMuller/boost-core/blob/main/CHANGELOG.md)
and [`UPGRADING.md`](https://github.com/SanderMuller/boost-core/blob/main/UPGRADING.md).

## Adding a tag is a breaking change

This one is easy to miss, because it is a content change rather than a code
change.

Adding `metadata.boost-tags` to a skill that already ships removes that skill
from every project that has not declared the tag. Treat it as consumer-breaking:
announce it, and prefer a new skill over re-tagging an established one.

## Family package versions

Each family package versions independently and declares a caret constraint on
the engine. Composer resolves the pair, so you upgrade the wrapper and the engine
follows:

```bash
composer update sandermuller/project-boost-laravel --with-dependencies
```

`vendor/bin/boost doctor --check-versions` compares your installed packages
against Packagist. It is opt-in because it makes one HTTP call per package.
