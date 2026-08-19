# Skill catalog

Everything `sandermuller/boost-skills` ships. A skill with no tag ships to every
project that allowlists the vendor. A tagged skill ships only where the project
declares every tag it carries.

## Skills

| Skill | What it does | Tags |
|---|---|---|
| `ai-guidelines` | Create and maintain AI skills and guideline files (`.ai/`, `CLAUDE.md`, `AGENTS.md`) | — |
| `autoresearch` | Autonomous performance loop: benchmark, change code, then keep or revert by measured result | `php` |
| `backend-quality` | Two-tier PHP quality gate: Pint and related tests on every change, PHPStan and the full suite on completion | `php` |
| `bug-fixing` | Test-driven bug workflow: reproduce with a failing test, then fix it | — |
| `clarify` | Turn a fuzzy ask into sharp, fact-checked intent. The shared core of `interview` and `promptimize` | — |
| `clean-specs` | Remove spec files whose work is merged to the base branch, keeping only live work | — |
| `code-review` | Review recent changes across functionality, code quality, security, and tests | — |
| `codex-review` | Request an independent review from the OpenAI Codex CLI, apply the warranted fixes, re-review until clean | — |
| `deploying-laravel-cloud` | Deploy and manage Laravel applications on Laravel Cloud through the `cloud` CLI | `laravel-cloud` `hosting` |
| `eloquent-models` | Create and maintain Eloquent models with column and relation constants, docblocks, and foreign-key constants | `laravel` |
| `evaluate` | Self-review a full implementation and fix the issues it surfaces | — |
| `eye-verification` | A browser pass over a frontend change: resolve the testables, drive each one, publish the proof screenshots | `frontend` |
| `final-verification-review` | Closeout verdict: run the evaluate loop, dry-run the closeout preflight, report READY or NOT READY | `github` |
| `frontend-quality` | Frontend quality gate: type-checking, linting, the JS test suite, and a browser eye-verify for UI changes | `frontend` |
| `github-issue-updates` | Append a user-facing description and QA testables to a GitHub issue after a feature ships | `github-issues` |
| `humanizer` | Remove signs of AI-generated writing so text reads as natural and human | — |
| `implement-spec` | Implement a specification file phase by phase, with progress tracking | — |
| `interview` | Adversarially grill out a complex feature's requirements before writing its spec | — |
| `jira-create` | Create a Jira issue with a well-formed, user-facing description | `jira` |
| `jira-rework` | Research a Jira issue sent back for rework, then propose fix options | `jira` `github` |
| `jira-updates` | Update a Jira issue after its PR is created, and post Blocked-by-Question comments | `jira` |
| `migration-squash` | Create or review a Laravel migration squash safely, with a checklist for incomplete or data-losing baselines | `laravel` |
| `pr-review-feedback` | Apply PR review comments, evaluating each one critically before acting | `github` |
| `pre-release` | Pre-push gauntlet: Rector, Pint, the full test suite, PHPStan, and a doc-staleness audit | `php` `github` `release-automation` |
| `promptimize` | Turn a rough prompt into one optimized, model-agnostic prompt | — |
| `pull-requests` | Create and manage your own GitHub PRs through `gh`: write the description, verify, route by risk | `github` |
| `readme` | Author and maintain a README for a Composer package: shape, voice, and staleness audits | `release-automation` |
| `release-notes` | Draft GitHub release bodies: structure, voice, breaking-change callouts, and what to omit | `release-automation` |
| `resolve-conflicts` | Resolve git merge conflicts without dropping functionality from either side | — |
| `test-writing` | Write specific, descriptively named tests that follow Arrange-Act-Assert | — |
| `upgrading` | The canonical structure for `UPGRADING.md` in a Composer package | `release-automation` |
| `ux-review` | Weigh UX and UI options for a new feature, recommend an approach, and document the decision | — |
| `write-spec` | Write implementation-ready specification files with progress-trackable phases | — |

## Guidelines

Guidelines are always active. There is no on-demand activation. The sync folds
them into `CLAUDE.md`, `AGENTS.md`, and the other guidance files.

| Guideline | What it covers | Tags |
|---|---|---|
| `ask-user-question` | Avoid first- and second-person pronouns in AskUserQuestion payloads. Name the actor instead | — |
| `database-safety` | Never run destructive database commands. Treat the test database as test-runner-owned | `database` |
| `javascript` | JavaScript and TypeScript control-structure style: always use braces, no single-line conditionals | `frontend` |
| `migrations` | Self-contained migration files. Append columns rather than positioning them mid-table | `database` |
| `phpstan-fixing` | Fixing a PHPStan error: write a failing test first when it maps to a runtime bug | `php` |
| `signed-commits` | Never fall back to an unsigned commit when signing is enabled. Surface the failure instead | — |
| `single-issue-scope` | Keep each session, branch, and PR focused on exactly one issue | `single-issue-scope` |
| `verification-before-completion` | Run the verification command and read its output before claiming work is done | — |
| `voice` | One voice rule per writing surface: a routing table plus the Simplified Technical English rules | `voice` |

A guideline file stays frontmatter-free, for `laravel/boost` compatibility, so
its tags live in a sidecar `.boost-tags.yaml` manifest beside it.

## Tags {#tags}

| Tag | Meaning | Shipped by |
|---|---|---|
| `boost-extension` | Opt-in: extending the engine with custom skills and file emitters | `package-boost-php` |
| `database` | The project has a database | `boost-skills` |
| `frontend` | A frontend toolchain: type-checking, linting, JS tests | `boost-skills` |
| `github` | Hosted on GitHub | `boost-skills` |
| `github-issues` | Issue tracking in GitHub Issues | `boost-skills` |
| `hosting` | The project deploys to a hosted platform. Parent of the platform-specific tags | `boost-skills` |
| `jira` | Issue tracking in Jira | `boost-skills` |
| `laravel` | The project uses the Laravel framework | `boost-skills` |
| `laravel-cloud` | The application deploys to Laravel Cloud. Pair with `hosting` | `boost-skills` |
| `php` | A PHP toolchain: Pint, PHPStan, Rector | `boost-skills` |
| `release-automation` | Opt-in: release-flow content — README authoring, release notes, `UPGRADING.md`, CI changelog automation | `boost-skills`, `package-boost-php` |
| `single-issue-scope` | Opt-in: enforce single-issue PR, branch, and session discipline | `boost-skills` |
| `voice` | Opt-in: route every writing surface to one voice rule | `boost-skills` |

`github` and `github-issues` are independent. `github` covers any GitHub-hosted
repository and is what the PR and release skills use. `github-issues` is the
narrower tag for projects that track issues in GitHub Issues. A repository hosted
on GitHub but tracking issues in Jira declares `github` and not `github-issues`.

The engine ships a broader `Tag` enum with cases no skill in this catalog targets
yet — `Tag::Filament`, `Tag::Livewire`, `Tag::Volt`, `Tag::Inertia`, `Tag::Flux`,
`Tag::Pest`, `Tag::Tailwind`, and others. Declaring one is harmless, and it
survives a re-run of the `boost install` picker.

## Hand-offs

Several skills call others, and declare it with `metadata.boost-requires`:

- `interview` and `promptimize` both build on `clarify`;
- `interview` then hands off to `write-spec`;
- `evaluate` runs `code-review` and `codex-review`;
- `pre-release` drafts documents through `readme`, `release-notes`, and
  `upgrading`;
- `pull-requests`, `pr-review-feedback`, and `jira-rework` each run their
  base-sync merge through `resolve-conflicts`.

Whenever one of these skills ships, everything it requires ships too — including
a skill your `withTags()` would otherwise have filtered out. See
[Tags and dependencies](/guide/tags-and-dependencies).

## Project Conventions

Several skills reference project-specific values: a Jira project key, the GitHub
owner and repository, branch patterns, the PR title format, the test framework.
The catalog ships a JSONSchema vocabulary at
`resources/boost/conventions-schema.json` that names those slots, and you fill
them in `boost.php`:

```php
->withConventions([
    'schema-version' => 1,
    'jira' => ['project_key' => 'HPB'],
    'github' => ['owner' => 'my-org', 'repo' => 'my-app'],
])
```

The twelve slot groups are `jira`, `github`, `branches`, `pr`, `testing`,
`quality`, `codex`, `spec`, `mcp`, `translations`, `fixtures`, and `review`. All
are optional; only `schema-version: 1` is required at the root. A group you do
declare must carry its own required leaves — `jira.project_key`, for example.

The mechanism, the token syntax, and the tooling are covered in
[Project Conventions](/guide/conventions).
