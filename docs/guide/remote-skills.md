# Remote skill sources

Skills can come straight from a GitHub repo, alongside your host `.ai/skills/`
and your allowlisted Composer packages. The
[Skill sources](/guide/skill-sources#remote-sources) summarises the flow; this page has
the details.

## The `boost remote` picker

```console
$ vendor/bin/boost remote mattpocock/skills
```

It resolves the ref and works out how the repo ships its skills: as `.skill`
release assets (bundle mode), or as directories in the repo (path mode). Then it
reads each skill's frontmatter and shows a checklist with descriptions, tags, and
any clash with a skill you already receive. Checked skills land in
`withRemoteSkills()`; unchecked ones are removed.

The command adds a picked skill's `boost-requires` dependencies when the same
repo publishes them. A skill whose tags aren't in your `withTags()` would never
ship, so it offers to declare those too. What it downloaded stays in the cache,
and the `boost sync` it offers to run afterwards needs no network.

| Flag | Effect |
|------|--------|
| `--ref=<tag\|branch\|sha>` | Pin the source. Without it a new entry gets `latest`; an existing entry keeps the version it has. |
| `--mode=bundle\|path` | Override auto-detection. Bundle wins when a repo publishes both. |

The repo argument takes `<owner>/<repo>` or any GitHub URL; omit it and the
command asks. The picker needs a terminal, so under `--no-interaction` it
explains how to write the entry by hand instead.

## Declaring sources by hand

The picker writes these entries for you, but they are plain config:

```php
use SanderMuller\BoostCore\Skills\Remote\RemoteSkillSource;

return BoostConfig::configure()
    ->withAgents([Agent::CLAUDE_CODE])
    ->withRemoteSkills([
        // Bundle mode: fetch the named `.skill` release asset and unzip it.
        RemoteSkillSource::githubBundle('peterfox/agent-skills', 'v1.2.0', [
            'composer-upgrade',
            'phpstan-developer',
        ]),

        // Path mode: fetch the repo tarball at a ref and extract named subdirs.
        // `.` covers a whole-repo-is-one-skill layout.
        RemoteSkillSource::githubPath('mattpocock/skills', 'main', [
            'grill-with-docs' => 'skills/engineering/grill-with-docs',
        ]),
    ]);
```

Each fetched skill fans out exactly like host and vendor skills: same layout,
same `withTags()` filtering, same `withExcludedSkills(['<owner>/<repo>:<name>'])`
deny-list. Removing an entry prunes its output on the next sync.

## Operational details

- **Cache.** Bundles and tarballs land under
  `${BOOST_CACHE_HOME:-${XDG_CACHE_HOME:-$HOME/.cache}}/boost/remote-skills/`.
  Pinned refs (a tag or 40-char SHA) cache forever; moving refs (`main`, a branch)
  re-resolve every 24h.
- **Offline.** `boost sync --check` never hits the network. `boost doctor` lists
  every source, flags moving refs, and reports cache presence.
- **Rate limit.** Anonymous GitHub caps at 60 req/h. Set `BOOST_GITHUB_TOKEN`
  (any token with `public_repo` scope) to lift it to 5000/h. Cold CI runs and
  `boost doctor` over many sources need it.
- **Trust.** Sources are opt-in by full path: `peterfox/agent-skills:composer-upgrade`
  grants access to nothing else in the repo. Pin to a tag or SHA in production;
  moving refs are convenient, but a source-side push silently changes what lands.
  Archive extraction rejects path traversal, absolute paths, symlinks, and
  oversized payloads (200 MB total / 50 MB per file / 10000 entries), and any
  violation rejects the whole source rather than extracting part of it.
- **Strict mode.** `BOOST_REMOTE_STRICT=1` escalates any source failure to a
  sync-aborting error. Default is warn-and-skip.

## Publishing a source

Treat the `SKILL.md` frontmatter `name` as durable public API, since renaming it
breaks moving-ref consumers. Keep source dirs symlink-free, because extraction
rejects any symlinked entry. Align `metadata.boost-tags` with the family's tag
vocabulary.
