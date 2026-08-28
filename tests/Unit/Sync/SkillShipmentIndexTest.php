<?php declare(strict_types=1);

use SanderMuller\BoostCore\Sync\SkillShipmentIndex;
use SanderMuller\BoostCore\Sync\SkillShipmentStatus;
use SanderMuller\BoostCore\Sync\SyncResult;
use SanderMuller\BoostCore\Sync\WriteAction;
use SanderMuller\BoostCore\Sync\WrittenFile;

/**
 * @param  list<WrittenFile>  $writes
 * @param  list<array{skill: string, shadowedVendor: string}>  $skillShadows
 * @param  list<array{guideline: string, shadowedVendor: string}>  $guidelineShadows
 */
function shipmentResult(array $writes, array $skillShadows = [], array $guidelineShadows = []): SyncResult
{
    return new SyncResult(
        writes: $writes,
        emitters: [],
        errors: [],
        hostShadows: $skillShadows,
        hostGuidelineShadows: $guidelineShadows,
        check: false,
    );
}

it('reads a shipped skill name out of any agent skills directory', function (): void {
    // Every agent target today ends its skills dir in `/skills`, but that is not
    // the frozen part of the contract — `skillsDirectoryRelative()` is. A caller
    // matching `#/skills/([^/]+)/SKILL\.md$#` itself is coupled to a layout
    // boost-core is free to change, and would silently report every skill as
    // not-shipped the day it did.
    $index = SkillShipmentIndex::from(shipmentResult([
        new WrittenFile('.claude/skills/alpha/SKILL.md', '/p/.claude/skills/alpha/SKILL.md', WriteAction::WROTE),
        new WrittenFile('.agents/skills/beta/SKILL.md', '/p/.agents/skills/beta/SKILL.md', WriteAction::UNCHANGED),
        new WrittenFile('.cursor/skills/gamma/SKILL.md', '/p/.cursor/skills/gamma/SKILL.md', WriteAction::WOULD_WRITE),
    ]));

    expect($index->isShipped('alpha'))->toBeTrue()
        ->and($index->isShipped('beta'))->toBeTrue()
        ->and($index->isShipped('gamma'))->toBeTrue()
        ->and($index->shippedNames())->toBe(['alpha', 'beta', 'gamma']);
});

it('does not count a deleted or symlink-skipped skill as shipped', function (): void {
    $index = SkillShipmentIndex::from(shipmentResult([
        new WrittenFile('.claude/skills/gone/SKILL.md', '/p/.claude/skills/gone/SKILL.md', WriteAction::DELETED),
        new WrittenFile('.claude/skills/linked/SKILL.md', '/p/.claude/skills/linked/SKILL.md', WriteAction::SKIPPED_SYMLINK),
    ]));

    expect($index->isShipped('gone'))->toBeFalse()
        ->and($index->isShipped('linked'))->toBeFalse();
});

it('ignores an asset sibling so it is never mistaken for a skill', function (): void {
    $index = SkillShipmentIndex::from(shipmentResult([
        new WrittenFile('.claude/skills/alpha/SKILL.md', '/p/.claude/skills/alpha/SKILL.md', WriteAction::WROTE),
        new WrittenFile('.claude/skills/alpha/rules/x.md', '/p/.claude/skills/alpha/rules/x.md', WriteAction::WROTE),
    ]));

    expect($index->shippedNames())->toBe(['alpha']);
});

it('maps a skill and a guideline to the vendor copy it shadowed', function (): void {
    $index = SkillShipmentIndex::from(shipmentResult(
        [],
        [['skill' => 'alpha', 'shadowedVendor' => 'acme/skills']],
        [['guideline' => 'style', 'shadowedVendor' => 'acme/rules']],
    ));

    expect($index->shadowedVendorFor('alpha'))->toBe('acme/skills')
        ->and($index->shadowedVendorFor('missing'))->toBeNull()
        ->and($index->guidelineShadowedVendorFor('style'))->toBe('acme/rules');
});

it('classifies why a skill did not ship', function (): void {
    // The vocabulary is shared so two entry points cannot describe one skill
    // differently. The PRESENTATION stays with each CLI — boost-core has no
    // business freezing another package's colours or column widths.
    $index = SkillShipmentIndex::from(shipmentResult(
        [new WrittenFile('.claude/skills/alpha/SKILL.md', '/p/.claude/skills/alpha/SKILL.md', WriteAction::WROTE)],
        [['skill' => 'beta', 'shadowedVendor' => 'acme/skills']],
    ));

    expect($index->statusFor('alpha', ['any']))->toBe(SkillShipmentStatus::SHIPPED)
        ->and($index->statusFor('beta', ['any']))->toBe(SkillShipmentStatus::SHADOWED)
        ->and($index->statusFor('gamma', ['docs']))->toBe(SkillShipmentStatus::TAG_FILTERED)
        ->and($index->statusFor('delta', []))->toBe(SkillShipmentStatus::EXCLUDED);
});
