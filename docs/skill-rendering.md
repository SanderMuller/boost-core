# Skill rendering

Skill files default to plain markdown (`SKILL.md`). For template-flavored content
(Blade, Twig, anything needing a render step), register a `SkillRenderer` in
`boost.php`:

```php
use SanderMuller\ProjectBoostLaravel\Rendering\BladeRenderer;

return BoostConfig::configure()
    ->withAgents([Agent::CLAUDE_CODE])
    ->withSkillRenderers([new BladeRenderer]);
```

## Dispatch

The dispatcher matches longest-extension-first, so a `BladeRenderer` claiming
`blade.php` handles `SKILL.blade.php` rather than losing it to a renderer that
claims `php`. The built-in `PassthroughRenderer` always handles `.md`.

## Failure handling

Render failures warn and skip by default, recorded in `SyncResult::errors`.
`BOOST_RENDER_STRICT=1` escalates the first failure to a sync-aborting error.

A source whose extension has **no** registered renderer, such as a
`SKILL.blade.php` in a project with no `BladeRenderer`, is flagged by both
`boost sync` and `boost doctor`. Register a renderer for it, or rename it to
`SKILL.md`.

## Writing a renderer

The `SkillRenderer` contract is `@api` and locked at 1.0. Plugin authors writing
renderers, `FileEmitter`s, or a `BoostWrapperContract` should work from
[`PUBLIC_API.md`](../PUBLIC_API.md), which pins the frozen contract surface.
Implementations need a parameterless constructor.
