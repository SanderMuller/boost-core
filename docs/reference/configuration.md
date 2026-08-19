# Configuration reference

`boost.php` returns a `BoostConfigBuilder`. Put it in the repository root, or in
`.config/boost.php` if you prefer to keep the root clean. Declaring both is an
error.

```php
<?php declare(strict_types=1);

use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Enums\Agent;
use SanderMuller\BoostCore\Enums\Tag;

return BoostConfig::configure()
    ->withAgents([Agent::CLAUDE_CODE, Agent::CURSOR])
    ->withAllowedVendors(['sandermuller/boost-skills'])
    ->withTags([Tag::Php]);
```

Run `vendor/bin/boost install` to scaffold the file and pick the agents, the
vendor allowlist, and the tags interactively.

## Setter semantics

Every `with*()` method **replaces** its collection, so calling one twice keeps
only the last call. `withSkillRenderers()` is the exception: it accumulates.
`withTags()` also normalizes and de-duplicates, and `withRemoteSkills()` rejects
a duplicate `(source, version, mode)` entry.

## Methods

| Method | Default | What it does |
|---|---|---|
| `withAgents(list<Agent>)` | `[]` | The agents that receive files. Nothing is emitted until you name at least one |
| `withAllowedVendors(list<string>)` | `[]` | Composer packages allowed to supply skills and guidelines, as `vendor/package` |
| `withTags(list<Tag\|string>)` | `[]` | The topic tags this project wants. A vendor skill ships only when every tag it declares is listed here |
| `withExcludedSkills(list<string>)` | `[]` | Drop specific vendor skills regardless of tags, as `vendor/package:skill-name` |
| `withExcludedGuidelines(list<string>)` | `[]` | The guideline counterpart, as `vendor/package:guideline-name`. The only lever for an untagged vendor guideline |
| `withRemoteSkills(list<RemoteSkillSource>)` | `[]` | Non-Composer sources: GitHub `.skill` release bundles, or cherry-picked subdirectories |
| `withConventions(array)` | `[]` | Project Conventions slot values that vendor skills read at agent-read time |
| `withSkillRenderers(list<SkillRenderer>)` | passthrough only | Template renderers for non-`.md` skill files. Accumulates across calls |
| `withDisabledRenderers(list<string>)` | `[]` | Drop a renderer by class name. Listing the passthrough renderer is a no-op |
| `withDisabledEmitters(list<string>)` | `[]` | Skip an emitter class during sync |
| `withGitignoreManagement(bool)` | `true` | Whether boost maintains its managed block in `.gitignore` |
| `withSkillsPath(string)` | `.ai/skills` | Where host skills are read from |
| `withGuidelinesPath(string)` | `.ai/guidelines` | Where host guidelines are read from |
| `withCommandsPath(string)` | `.ai/commands` | Where host command templates are read from |

## Agents

`Agent` is an enum, so your IDE completes the list:

`Agent::CLAUDE_CODE`, `Agent::CURSOR`, `Agent::COPILOT`, `Agent::CODEX`,
`Agent::GEMINI`, `Agent::JUNIE`, `Agent::KIRO`, `Agent::OPENCODE`, `Agent::AMP`,
`Agent::ANTIGRAVITY`.

De-selecting an agent reaps the files it owned on the next sync. See
[File ownership](/guide/file-ownership).

## Tags

`Tag` cases give you autocompletion, and raw strings are accepted because the
vocabulary is open:

```php
->withTags([Tag::Php, 'jira'])
```

`vendor/bin/boost tags` lists every tag your installed packages declare, and what
each one would unlock. The filtering rule is in
[Tags and dependencies](/guide/tags-and-dependencies).

## Related pages

- [Project Conventions](/guide/conventions) — the slot schema and the token syntax
- [Remote skills](/guide/remote-skills) — writing `RemoteSkillSource` entries by hand
- [Skill rendering](/guide/skill-rendering) — writing your own renderer
- [`PUBLIC_API.md`](https://github.com/SanderMuller/boost-core/blob/main/PUBLIC_API.md) — the frozen semver surface
