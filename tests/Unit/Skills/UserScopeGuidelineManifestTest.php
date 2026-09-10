<?php declare(strict_types=1);

use SanderMuller\BoostCore\Skills\UserScopeGuidelineManifest;

/**
 * Write `.boost-user-scope.yaml` (when $yaml is non-null) into a throwaway dir,
 * load the manifest from it, return it.
 */
function loadUserScopeManifest(?string $yaml): UserScopeGuidelineManifest
{
    $dir = sys_get_temp_dir() . '/boost-usgm-' . bin2hex(random_bytes(8));
    mkdir($dir, 0o755, recursive: true);

    if ($yaml !== null) {
        file_put_contents($dir . '/' . UserScopeGuidelineManifest::FILENAME, $yaml);
    }

    try {
        return UserScopeGuidelineManifest::load($dir);
    } finally {
        cleanupTestDir($dir);
    }
}

it('marks nothing eligible when the sidecar is absent', function (): void {
    expect(loadUserScopeManifest(null)->isEligible('voice.md'))->toBeFalse();
});

it('marks the listed guidelines eligible', function (): void {
    $manifest = loadUserScopeManifest("- voice.md\n- laravel/voice.md\n");

    expect($manifest->isEligible('voice.md'))->toBeTrue()
        ->and($manifest->isEligible('laravel/voice.md'))->toBeTrue()
        ->and($manifest->isEligible('migrations.md'))->toBeFalse()
        ->and($manifest->listedPaths())->toBe(['voice.md', 'laravel/voice.md']);
});

it('fails closed on a map, which is not the documented shape', function (): void {
    expect(loadUserScopeManifest("voice.md: true\n")->isEligible('voice.md'))->toBeFalse();
});

it('fails closed on unparseable YAML', function (): void {
    expect(loadUserScopeManifest("- voice.md\n\t- broken\n")->isEligible('voice.md'))->toBeFalse();
});

it('reads a comment-only sidecar as listing nothing', function (): void {
    expect(loadUserScopeManifest("# nothing yet\n")->listedPaths())
        ->toBeEmpty();
});

it('ignores a non-string entry', function (): void {
    expect(loadUserScopeManifest("- voice.md\n- 42\n")->listedPaths())->toBe(['voice.md']);
});
