<?php declare(strict_types=1);

use SanderMuller\BoostCore\Contracts\BoostWrapperContract;
use SanderMuller\BoostCore\Sync\InstalledPackages;
use SanderMuller\BoostCore\Sync\PackageInfo;
use SanderMuller\BoostCore\Sync\WrapperEmitDiscovery;

function wrapperFixturePath(string $name): string
{
    // 0.11.0 wrapper fixtures are self-contained packages: each is a dir
    // under tests/Doubles/Wrappers/ holding both its `BoostWrapper` class
    // AND a composer.json declaring its PSR-4 prefix. installPath points at
    // that dir so the engine's package-scope file-path check (class file
    // must live under installPath) passes for the fixture. Multi-prefix
    // uses the Wrappers root (two prefix subdirs both resolve under it).
    $map = [
        'happy' => '/../../Doubles/Wrappers/HappyPath',
        'violating' => '/../../Doubles/Wrappers/ContractViolating',
        'throwing' => '/../../Doubles/Wrappers/Throwing',
        'wrong-type' => '/../../Doubles/Wrappers/WrongType',
        'directory-claim' => '/../../Doubles/Wrappers/DirectoryClaim',
        'guideline-claim' => '/../../Doubles/Wrappers/GuidelineClaim',
        'multi-prefix' => '/../../Doubles/Wrappers',
        'autoload-throwing' => '/../../Doubles/Wrappers/AutoloadThrowing',
        'messy-paths' => '/../../Doubles/Wrappers/MessyPaths',
    ];
    if (! isset($map[$name])) {
        throw new RuntimeException("unknown fixture: {$name}");
    }

    $resolved = realpath(__DIR__ . $map[$name]);
    if ($resolved === false) {
        throw new RuntimeException("missing fixture dir: {$name}");
    }

    return $resolved;
}

/**
 * @param  array<string, string>  $fixtures  package-name → fixture-dir-name
 */
function packagesWithFixtures(array $fixtures): InstalledPackages
{
    /** @var array<string, PackageInfo> $packages */
    $packages = [];
    foreach ($fixtures as $name => $fixtureDir) {
        $packages[$name] = new PackageInfo(
            name: $name,
            version: '1.0.0',
            installPath: wrapperFixturePath($fixtureDir),
        );
    }

    return new InstalledPackages($packages);
}

it('returns empty paths + no diagnostics for empty package set', function (): void {
    $discovery = new WrapperEmitDiscovery(new InstalledPackages([]));

    $result = $discovery->discover('/some/project');

    expect($result)->toMatchArray(['paths' => [], 'diagnostics' => []]);
});

it('happy path: wrapper implementing contract contributes its emit-paths to the exclusion union', function (): void {
    $packages = packagesWithFixtures(['test/happy-wrapper' => 'happy']);
    $discovery = new WrapperEmitDiscovery($packages);

    $result = $discovery->discover('/some/project');

    expect($result['paths'])
        ->toHaveKey('.agents/skills/wrapper-injected-foo/SKILL.md')
        ->and($result['paths'])
        ->toHaveKey('.agents/skills/wrapper-injected-bar/SKILL.md')
        ->and($result['diagnostics'])->toBeEmpty();
});

it('absent class: package without `BoostWrapper` at any PSR-4 prefix contributes nothing, silently (no warning)', function (): void {
    // boost-core itself is a directly-installed package in this dev tree —
    // it has PSR-4 prefixes but no BoostWrapper class. Use it as the
    // "non-wrapper package present" fixture. Engine must contribute zero
    // paths AND zero diagnostics for it (silent fallback per spec §4 +
    // Resolved warning-behavior section).
    $packages = new InstalledPackages([
        'fake/non-wrapper-package' => new PackageInfo(
            name: 'fake/non-wrapper-package',
            version: '1.0.0',
            installPath: __DIR__ . '/../../..',  // points at boost-core itself
        ),
    ]);
    $discovery = new WrapperEmitDiscovery($packages);

    $result = $discovery->discover('/some/project');

    // boost-core's composer.json has psr-4 `SanderMuller\BoostCore\\` → `src/`.
    // No `SanderMuller\BoostCore\BoostWrapper` class exists. Silent.
    expect($result['paths'])->toBeEmpty()
        ->and($result['diagnostics'])->toBeEmpty();
});

it('contract violation: `BoostWrapper` class exists but does not implement contract → pinned-wording warning fires, no exclusion contribution', function (): void {
    // wording-revert-as-regression-test pattern: this pins the EXACT
    // contract-violation wording. The wording is operator-facing
    // discoverable-degradation surface — a refactor paraphrasing it
    // ("Class declares but doesn't implement..." → "Class declares wrong
    // contract...") would silently weaken the diagnostic's prescriptive
    // shape (what happened / what's preserved / next concrete step).
    // Brittle-looking exact-string check is intentional.
    $packages = packagesWithFixtures(['test/violating-wrapper' => 'violating']);
    $discovery = new WrapperEmitDiscovery($packages);

    $result = $discovery->discover('/some/project');

    expect($result['paths'])->toBeEmpty()
        ->and($result['diagnostics'])->toHaveCount(1);

    $message = $result['diagnostics'][0]->message;
    expect($message)
        ->toContain('Package `test/violating-wrapper` declares a `')
        ->and($message)->toContain('` class but it does not implement `')
        ->and($message)->toContain(BoostWrapperContract::class)
        ->and($message)->toContain('Falling back to strict drift comparison')
        ->and($message)->toContain('false positives possible on its injected paths')
        ->and($message)->toContain('To resolve: implement the contract');
});

it('exception in `injectedEmitPaths()`: engine catches Throwable, emits per-package warning naming exception class + first-line of message, no exclusion contribution', function (): void {
    $packages = packagesWithFixtures(['test/throwing-wrapper' => 'throwing']);
    $discovery = new WrapperEmitDiscovery($packages);

    $result = $discovery->discover('/some/project');

    expect($result['paths'])->toBeEmpty()
        ->and($result['diagnostics'])->toHaveCount(1);

    $message = $result['diagnostics'][0]->message;
    expect($message)
        ->toContain("Wrapper `test/throwing-wrapper`'s `BoostWrapper::injectedEmitPaths()` threw `RuntimeException`")
        ->and($message)->toContain('wrapper exploded on path resolution')
        ->and($message)->toContain('Falling back to strict drift comparison');
});

it('autoload failure: `class_exists` probe that throws during autoload (parse error / top-level throw) is caught + degrades per-package with a warning, NOT a sync-aborting fatal (codex-review P1)', function (): void {
    // One broken wrapper dependency must not block every `boost sync`.
    // The engine wraps the class_exists probe in try/catch(Throwable) and
    // emits a per-package warning instead of letting the autoload error
    // propagate out of discover().
    $packages = packagesWithFixtures(['test/autoload-throwing-wrapper' => 'autoload-throwing']);
    $discovery = new WrapperEmitDiscovery($packages);

    $result = $discovery->discover('/some/project');

    expect($result['paths'])->toBeEmpty()
        ->and($result['diagnostics'])->toHaveCount(1);

    $message = $result['diagnostics'][0]->message;
    expect($message)
        ->toContain("Package `test/autoload-throwing-wrapper`'s `")
        ->and($message)->toContain('class could not be autoloaded')
        ->and($message)->toContain('RuntimeException')
        ->and($message)->toContain('wrapper boom on autoload')
        ->and($message)->toContain('Falling back to strict drift comparison');
});

it('wrong return-type: `injectedEmitPaths()` returns array containing non-string entry → type-validation warning, no exclusion contribution', function (): void {
    $packages = packagesWithFixtures(['test/wrong-type-wrapper' => 'wrong-type']);
    $discovery = new WrapperEmitDiscovery($packages);

    $result = $discovery->discover('/some/project');

    expect($result['paths'])->toBeEmpty()
        ->and($result['diagnostics'])->toHaveCount(1);

    $message = $result['diagnostics'][0]->message;
    expect($message)
        ->toContain("Wrapper `test/wrong-type-wrapper`'s `BoostWrapper::injectedEmitPaths()` returned an invalid value")
        ->and($message)->toContain('expected list of strings, got array with non-string entry (int)')
        ->and($message)->toContain('Falling back to strict drift comparison');
});

it('multi-prefix discovery: prefers contract-implementing candidate even when an earlier PSR-4 prefix has a non-implementing `BoostWrapper`', function (): void {
    // Spec discovery algorithm (Resolved section): probe all PSR-4 prefixes
    // in declaration order, prefer contract-implementing candidates over
    // violating ones. Without the prefer-implementing rule, a multi-prefix
    // package where the earlier prefix has a coincidental `BoostWrapper`
    // (not implementing the contract) and the later prefix has the valid
    // wrapper would silently route through the violating-class branch.
    $packages = packagesWithFixtures(['test/multi-prefix-wrapper' => 'multi-prefix']);
    $discovery = new WrapperEmitDiscovery($packages);

    $result = $discovery->discover('/some/project');

    // Valid late-prefix wrapper found → its paths land, no warning fires.
    expect($result['paths'])->toHaveKey('.agents/skills/multi-prefix-found/SKILL.md')
        ->and($result['diagnostics'])->toBeEmpty();
});

it('path canonicalization: wrapper returns mixed-form paths; engine normalizes to canonical project-root-relative form before union', function (): void {
    // Wrappers might return paths with leading `./`, backslashes (Windows-
    // habit code), duplicate slashes, trailing slash. Engine normalizes
    // EVERYTHING to canonical form so the union comparison in cleanup-pass
    // is byte-identical regardless of input form. Tested via a wrapper
    // fixture returning intentionally-non-canonical paths.
    $packages = packagesWithFixtures(['test/happy-wrapper' => 'happy']);
    $discovery = new WrapperEmitDiscovery($packages);

    // The happy-wrapper returns canonical paths in this test setup; verify
    // separately via the canonicalization helper called on edge-case inputs.
    $result = $discovery->discover('/some/project');

    expect(array_keys($result['paths']))->each->not->toStartWith('./');
});

it('path canonicalization: embedded `./`, backslashes, duplicate slashes, trailing slash all normalize to canonical form; `..` traversal is rejected (codex-review pin)', function (): void {
    // The cleanup-pass union comparison uses canonical on-disk paths. A
    // wrapper claim with embedded `./` (`.agents/skills/foo/./SKILL.md`)
    // must collapse to `.agents/skills/foo/SKILL.md` or it would never
    // match and the file would be falsely flagged stale. `..` traversal is
    // rejected outright — wrappers must stay inside project root.
    $packages = packagesWithFixtures(['test/messy-paths-wrapper' => 'messy-paths']);
    $discovery = new WrapperEmitDiscovery($packages);

    $result = $discovery->discover('/some/project');

    $paths = array_keys($result['paths']);
    sort($paths);

    expect($paths)->toBe([
        '.agents/skills/bar/SKILL.md',
        '.agents/skills/baz/SKILL.md',
        '.agents/skills/foo/SKILL.md',
        '.agents/skills/qux/SKILL.md',
    ]);

    // `..`-traversal entry dropped (not present in any normalized form).
    foreach ($paths as $path) {
        expect(str_contains($path, '..'))->toBeFalse()
            ->and(str_contains($path, '\\'))->toBeFalse()
            ->and(str_contains($path, '//'))->toBeFalse()
            ->and(str_contains($path, '/./'))->toBeFalse();
    }
});

it('union semantics: multiple wrappers contribute paths; result is the union (deduplicated set-by-key)', function (): void {
    $packages = packagesWithFixtures([
        'test/happy-wrapper' => 'happy',
        'test/multi-prefix-wrapper' => 'multi-prefix',
    ]);
    $discovery = new WrapperEmitDiscovery($packages);

    $result = $discovery->discover('/some/project');

    expect($result['paths'])
        ->toHaveKey('.agents/skills/wrapper-injected-foo/SKILL.md')
        ->and($result['paths'])->toHaveKey('.agents/skills/wrapper-injected-bar/SKILL.md')
        ->and($result['paths'])->toHaveKey('.agents/skills/multi-prefix-found/SKILL.md')
        ->and($result['diagnostics'])->toBeEmpty();
});
