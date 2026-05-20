<?php declare(strict_types=1);

use SanderMuller\BoostCore\Enums\Tag;

it('normalizes a tag — lowercase and trim', function (): void {
    expect(Tag::normalize('  JIRA '))->toBe('jira')
        ->and(Tag::normalize('Php'))->toBe('php')
        ->and(Tag::normalize('GitHub-Issues'))->toBe('github-issues')
        ->and(Tag::normalize('frontend'))->toBe('frontend');
});

it('normalizes a blank tag to the empty string', function (): void {
    expect(Tag::normalize('   '))
        ->toBeEmpty()
        ->and(Tag::normalize(''))
        ->toBeEmpty()
        ->and(Tag::normalize("\t\n"))
        ->toBeEmpty();
});

it('leaves enum case values unchanged through normalization', function (): void {
    foreach (Tag::cases() as $case) {
        expect(Tag::normalize($case->value))->toBe($case->value);
    }
});
