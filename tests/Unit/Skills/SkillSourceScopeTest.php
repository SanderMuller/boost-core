<?php declare(strict_types=1);

use SanderMuller\BoostCore\Skills\SkillSourceScope;
use Symfony\Component\Finder\SplFileInfo;

/**
 * The single skill-source layout rule shared by SkillLoader (what ships) and
 * UnrenderableSourceScanner (what doctor reports) — locking it guarantees the
 * two classify identically (0.22.0 #A).
 *
 * Finder's SplFileInfo carries (absolutePath, relativePath, relativePathname);
 * getRelativePath() is the directory portion ('' at top level).
 */
function scopeFile(string $relativePath, string $relativePathname, string $filename): SplFileInfo
{
    return new SplFileInfo('/abs/' . $relativePathname . '#' . $filename, $relativePath, $relativePathname);
}

it('accepts top-level flat skill files (any registered extension)', function (): void {
    expect(SkillSourceScope::isSkillSource(scopeFile('', 'foo.md', 'foo.md')))->toBeTrue()
        ->and(SkillSourceScope::isSkillSource(scopeFile('', 'foo.blade.php', 'foo.blade.php')))->toBeTrue();
});

it('accepts depth-1 SKILL.* entry files', function (): void {
    expect(SkillSourceScope::isSkillSource(scopeFile('mcp-development', 'mcp-development/SKILL.md', 'SKILL.md')))->toBeTrue()
        ->and(SkillSourceScope::isSkillSource(scopeFile('mcp-development', 'mcp-development/SKILL.blade.php', 'SKILL.blade.php')))->toBeTrue();
});

it('rejects depth-1 NON-SKILL files (not a flat skill, not the entry)', function (): void {
    expect(SkillSourceScope::isSkillSource(scopeFile('mcp-development', 'mcp-development/notes.md', 'notes.md')))->toBeFalse();
});

it('rejects nested reference/example files (the phantom-skill bug)', function (): void {
    expect(SkillSourceScope::isSkillSource(scopeFile('mcp-development/references', 'mcp-development/references/app.md', 'app.md')))->toBeFalse()
        ->and(SkillSourceScope::isSkillSource(scopeFile('mcp-development/references', 'mcp-development/references/SKILL.md', 'SKILL.md')))->toBeFalse();
});
