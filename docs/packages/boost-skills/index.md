# boost-skills

`sandermuller/boost-skills` is a **catalog** package: Sander Muller's personal
mix of AI agent skills for PHP projects and Composer packages. Adopt it if your
preferences align with his, or read it as a template for your own catalog.

::: info Where this package lives
[GitHub](https://github.com/SanderMuller/boost-skills) &middot; [Packagist](https://packagist.org/packages/sandermuller/boost-skills) &middot; [Releases](https://github.com/SanderMuller/boost-skills/releases) &middot; [Changelog](https://github.com/SanderMuller/boost-skills/blob/main/CHANGELOG.md)

Each family package has its own repository and its own release cadence. This site is built from the `boost-core` repository, so a documentation fix goes there, and a code issue goes to the repository above.
:::

It is not a dependency of the family wrappers. You require it and allowlist its
vendor, and then its skills ship alongside whatever else you already receive.

The package carries no runtime code. It is pure Markdown. A sync engine — this
family's engine, or `laravel/boost` — reads the skills and writes them into each
agent directory you configured.

## Install

Install it next to the family package for your role:

```bash
composer require --dev sandermuller/boost-skills sandermuller/package-boost-php
```

Swap `package-boost-php` for the member that matches your project. Then allowlist
the vendor, because a catalog ships nothing until you name it:

```php
return BoostConfig::configure()
    ->withAgents([
        Agent::CLAUDE_CODE,
        Agent::COPILOT,
        Agent::CODEX,
    ])
    ->withAllowedVendors([
        'sandermuller/boost-skills',
        'sandermuller/package-boost-php',
    ])
    ->withTags(['php', 'github']);
```

```bash
vendor/bin/boost install   # the picker offers the vendor; select it
vendor/bin/boost sync
```

`vendor/bin/boost tags` shows which tagged content is currently filtered out, so
you can see what declaring one more tag would unlock.

## Declare your tags

Most skills in the catalog are universal and ship everywhere. Some carry
capability tags naming what the project needs for the content to be useful.
`'php'` and `'github'` are the common starters. The full vocabulary is in the
[catalog](/packages/boost-skills/catalog#tags).

The tag **mechanism** is family-canonical and defined by the engine. The tag
**vocabulary** is this catalog's own choice. Another catalog may organise
differently. See [Tags and dependencies](/guide/tags-and-dependencies).

## Under `laravel/boost` instead

`laravel/boost` reads any Composer-distributed catalog, including this one, so
you can use it standalone and follow its own setup. Two family features go
inert there: tag filtering, and the Project Conventions slots. The skills carry
visible defaults, so a slot still reads as sensible wording even when nothing
resolves it.
