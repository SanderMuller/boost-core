<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Sync;

use SanderMuller\BoostCore\Agents\AgentTarget;

/**
 * Which skills a sync actually put on disk, and which host copies shadowed a
 * vendor's.
 *
 * **Why this is `@api`.** A wrapper package rendering its own `where` has to
 * answer "did this skill ship?" from a {@see SyncResult}, and the only route
 * was to pattern-match boost-core's emit paths — in practice a regex like
 * `#/skills/([^/]+)/SKILL\.md$#`. Every agent directory happens to end in
 * `/skills` today, so that works, but the frozen contract is
 * `AgentTarget::skillsDirectoryRelative()`, NOT the fact that its value ends
 * in a particular word. A layout change would leave that caller reporting
 * every skill as not-shipped, with no error — the same quiet-divergence shape
 * as a wrong verdict anywhere else.
 *
 * Owning the inverse mapping here keeps it next to
 * {@see AgentTarget::skillRelativePathForName()},
 * which produces the paths it reads back.
 *
 * @api Stable as of 1.4. Frozen surface: {@see from()}, {@see isShipped()},
 * {@see shippedNames()}, {@see shadowedVendorFor()},
 * {@see guidelineShadowedVendorFor()}, the two map getters, and
 * {@see statusFor()}.
 */
final readonly class SkillShipmentIndex
{
    /**
     * @param  array<string, true>  $shipped
     * @param  array<string, string>  $skillShadows  skill name => shadowed vendor
     * @param  array<string, string>  $guidelineShadows  guideline name => shadowed vendor
     */
    private function __construct(
        private array $shipped,
        private array $skillShadows,
        private array $guidelineShadows,
    ) {}

    public static function from(SyncResult $result): self
    {
        $shipped = [];
        foreach ($result->writes as $write) {
            // "Shipped" means this sync intends the file to be there: written,
            // already identical, or would-be-written in check mode. A DELETED
            // or WOULD_DELETE entry is the opposite. SKIPPED_SYMLINK is
            // excluded too — the path resolves through a symlink boost refuses
            // to follow, so whatever an agent reads there is not what boost
            // produced, and calling it shipped would overstate what happened.
            if (! in_array($write->action, [WriteAction::WROTE, WriteAction::UNCHANGED, WriteAction::WOULD_WRITE], strict: true)) {
                continue;
            }

            $name = self::skillNameFromEmitPath($write->relativePath);
            if ($name !== null) {
                $shipped[$name] = true;
            }
        }

        $skillShadows = [];
        foreach ($result->hostShadows as $shadow) {
            $skillShadows[$shadow['skill']] = $shadow['shadowedVendor'];
        }

        $guidelineShadows = [];
        foreach ($result->hostGuidelineShadows as $shadow) {
            $guidelineShadows[$shadow['guideline']] = $shadow['shadowedVendor'];
        }

        return new self($shipped, $skillShadows, $guidelineShadows);
    }

    public function isShipped(string $skillName): bool
    {
        return isset($this->shipped[$skillName]);
    }

    /**
     * @return list<string>  in first-seen write order
     */
    public function shippedNames(): array
    {
        return array_keys($this->shipped);
    }

    /**
     * The vendor package whose skill of this name was shadowed by a host copy,
     * or null when nothing was shadowed.
     */
    public function shadowedVendorFor(string $skillName): ?string
    {
        return $this->skillShadows[$skillName] ?? null;
    }

    public function guidelineShadowedVendorFor(string $guidelineName): ?string
    {
        return $this->guidelineShadows[$guidelineName] ?? null;
    }

    /**
     * The whole skill map, for a caller rendering a table rather than asking
     * about one name at a time.
     *
     * @return array<string, string>  skill name => shadowed vendor
     */
    public function shadowedVendorMap(): array
    {
        return $this->skillShadows;
    }

    /**
     * @return array<string, string>  guideline name => shadowed vendor
     */
    public function guidelineShadowedVendorMap(): array
    {
        return $this->guidelineShadows;
    }

    /**
     * Classify a resolved skill. `$tags` is the skill's own tag list, which
     * separates "filtered out because the project does not declare its tags"
     * — actionable, the operator can add one — from "absent for some other
     * reason", where tag advice would send them down a dead end.
     *
     * @param  list<string>  $tags
     */
    public function statusFor(string $skillName, array $tags): SkillShipmentStatus
    {
        if ($this->isShipped($skillName)) {
            return SkillShipmentStatus::SHIPPED;
        }

        if ($this->shadowedVendorFor($skillName) !== null) {
            return SkillShipmentStatus::SHADOWED;
        }

        return $tags === [] ? SkillShipmentStatus::EXCLUDED : SkillShipmentStatus::TAG_FILTERED;
    }

    /**
     * Read a skill name back out of an emit path. Emit paths are built as
     * `<skillsDirectoryRelative()>/<name>/SKILL.md`, so the name is the
     * segment before the file — no assumption about what the directory prefix
     * is called. An asset sibling (`<name>/rules/x.md`) has a deeper tail and
     * is ignored rather than mistaken for a skill of its own.
     */
    private static function skillNameFromEmitPath(string $relativePath): ?string
    {
        $segments = explode('/', str_replace('\\', '/', $relativePath));
        $file = array_pop($segments);
        if ($file !== 'SKILL.md') {
            return null;
        }

        $name = array_pop($segments);

        return $name === null || $name === '' ? null : $name;
    }
}
