# CLI reference

Every family package exposes the same binary at `vendor/bin/boost`.

| Command | Purpose |
|---|---|
| `boost install` | Scaffold `boost.php` (if missing), then run the interactive agent / vendor / tag picker |
| `boost new <skill\|guideline> <name>` | Scaffold a skill or guideline file with a frontmatter template (`--description`, `--force`) |
| `boost scan` | Re-run the vendor allowlist picker — use after installing packages that publish skills or guidelines |
| `boost remote [<owner>/<repo>]` | Read a GitHub repository of skills and pick which to declare in `withRemoteSkills()` (`--ref`, `--mode`) |
| `boost sync` | Fan skills, guidelines, and commands out to the selected agents |
| `boost sync --check` | Dry run — report drift, write nothing. Offline. Gate CI on this |
| `boost sync --scope=user [--all]` | User-scope sync, for globally-installed CLI tools |
| `boost where` | Origin-traced listing of every skill, guideline, and command that would ship |
| `boost where --diff=<name>` | Unified diff between a host override and the vendor copy |
| `boost where --conventions [--json]` | Resolved conventions slots, their provenance, and block keep/drop status |
| `boost doctor` | Offline health check — config, remote sources, cache, emitters, skill dependencies, token leaks |
| `boost doctor --check-versions` | Opt-in Packagist comparison for path-repository shadows. One HTTP call per package |
| `boost doctor --check-conventions` | Report conventions slot status: missing, unknown, file existence |
| `boost doctor --check-stale-paths` | Read-only audit of the retired-paths registry — what the next sync would clean up |
| `boost tags` | List available tags and their unlock counts across allowlisted vendors |
| `boost validate [--strict]` | Validate `withConventions([...])`, scan for leaked tokens, check skill dependencies |
| `boost slots [--missing\|--filled]` | List conventions slots, optionally filtered by fill state |
| `boost paths` | List the path globs boost manages |
| `boost convert-conventions` | Legacy one-shot: extract 0.8.x marker YAML into `boost.php`. Hidden, not a contract |

## Exit codes

`0` ok, `1` failure, `2` usage error.

`boost doctor` is **advisory**: it exits 0 unless the config itself fails to
load. Gate CI on `boost sync --check` or `boost validate --strict` instead.

```yaml
- name: Check agent config is in sync
  run: vendor/bin/boost sync --check && vendor/bin/boost validate --strict
```

## Laravel applications

With [`project-boost-laravel`](/packages/project-boost-laravel/), use the artisan
commands instead of the bare binary. They run through the Laravel container,
which bootstraps the Blade renderer and delivers `laravel/boost`'s bundled skills
to every agent:

```bash
php artisan project-boost:sync
php artisan project-boost:reconcile
```

Gate CI on the wrapper's command too. The bare one reads boost-core's own
sources only, and the flags differ: `--check` here, `--dry-run` there.

```yaml
- name: Check agent config is in sync
  run: php artisan project-boost:sync --dry-run
```

### The entry-point banner

A wrapper declares what it covers in its `composer.json`:

```json
{
    "extra": {
        "boost": {
            "entry-point": {
                "sync": "php artisan project-boost:sync"
            }
        }
    }
}
```

`vendor/bin/boost sync` then names that command on stderr and runs anyway.
Set `BOOST_STRICT_ENTRY_POINT=1` to refuse instead; that becomes the default in
the next major. Commands with no wrapper equivalent, such as `scan` and `tags`,
run and warn that the result is incomplete.

`boost doctor` lists the declared entry points and reports any it rejected.
`doctor` itself cannot be claimed, and when two packages claim one command the
first one Composer discovers wins.

See [Coexistence with `laravel/boost`](/guide/laravel-coexistence).
