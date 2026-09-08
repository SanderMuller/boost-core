<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Skills;

/**
 * A resolved subagent definition — a Claude Code `.claude/agents/<name>.md`
 * file a package or the host ships.
 *
 * NOT to be confused with `Agent` / `AgentTarget`, which are DELIVERY TARGETS
 * (Claude Code, Cursor, Codex…). A subagent is content boost ships TO a target;
 * an agent target is where content goes.
 *
 * Identity is the frontmatter `name`, which is also how Claude Code resolves a
 * dispatch — the file's path never namespaces it.
 *
 * @internal
 */
final readonly class Subagent
{
    /**
     * @param  array<string, mixed>  $frontmatter  Passed through to the emitted file verbatim — boost never rewrites `tools`, `disallowedTools`, `model` or any other key. The engine cannot verify a consumer's permission surface, so it does not police assertions about it.
     * @param  string|null  $sourceVendor  Composer `vendor/package` that published it. Null = host-authored from `.ai/subagents/`.
     * @param  list<string>  $tags  Normalized tags from `metadata.boost-tags`. Empty = untagged = ships everywhere.
     * @param  bool  $tagsValid  False when `metadata.boost-tags` is present but malformed — the subagent then fails closed, matching skills.
     */
    public function __construct(
        public string $name,
        public ?string $description,
        public array $frontmatter,
        public string $body,
        public string $sourcePath,
        public ?string $sourceVendor,
        public array $tags = [],
        public bool $tagsValid = true,
    ) {}

    public function isHostAuthored(): bool
    {
        return $this->sourceVendor === null;
    }

    /**
     * The `vendor/package:name` key a consumer's `withExcludedSkills()`
     * deny-list addresses this subagent by — or null for a host-authored one,
     * which the deny-list has no form to name.
     *
     * Subagents share the skill deny-list rather than getting one of their own:
     * the key shape is identical, and a consumer excluding a package's review
     * pass should not have to learn a second config surface for it.
     */
    public function excludeKey(): ?string
    {
        return $this->sourceVendor === null
            ? null
            : $this->sourceVendor . ':' . $this->name;
    }
}
