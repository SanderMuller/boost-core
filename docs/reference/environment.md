# Environment variables

Every variable is opt-in. Unset means the default behavior.

| Variable | Effect |
|---|---|
| `BOOST_SKIP_AUTOSYNC=1` | Skip the `BoostAutoSync` Composer-hook sync entirely |
| `BOOST_SKIP_GITIGNORE=1` | Skip managed `.gitignore` updates. Useful in CI and ephemeral Docker installs |
| `BOOST_GITHUB_TOKEN` | GitHub token with `public_repo` scope. Lifts remote-skill fetches from 60 to 5000 requests per hour |
| `BOOST_REMOTE_STRICT=1` | Escalate any remote-skill source failure to a sync-aborting error. Default: warn and skip |
| `BOOST_RENDER_STRICT=1` | Escalate the first skill-render failure to a sync-aborting error. Default: warn and skip |
| `BOOST_CACHE_HOME` | Override the remote-skill cache root. Defaults to `$XDG_CACHE_HOME`, then `~/.cache` |

## In CI

Two settings pay off in a pipeline:

```yaml
env:
  BOOST_SKIP_GITIGNORE: '1'      # the runner's checkout is throwaway
  BOOST_REMOTE_STRICT: '1'       # a silent remote skip must not pass the build
  BOOST_GITHUB_TOKEN: ${{ secrets.GITHUB_TOKEN }}
```

`BOOST_REMOTE_STRICT=1` applies to a real `boost sync`. Without it, a GitHub
outage downgrades a missing remote skill to a warning, and the sync writes an
incomplete skill set.

`boost sync --check` never touches the network. A remote source that is not in
the offline cache is reported as "would fetch on a real sync" rather than
fetched, so the check stays deterministic in CI.
