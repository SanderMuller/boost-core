<?php declare(strict_types=1);

use Composer\InstalledVersions;
use SanderMuller\BoostCore\Scripts\BoostAutoSync;

/**
 * A throwaway "tool" package — a composer.json with `$name` and,
 * optionally, one skill at `resources/boost/skills/sample-skill.md`.
 * Mirrors a globally-installed CLI tool like `sandermuller/repo-init`.
 */
function makeUserScopeToolFixture(string $name = 'acme/sample-tool', bool $withSkill = true): string
{
    $root = sys_get_temp_dir() . '/boost-uss-pkg-' . bin2hex(random_bytes(8));
    mkdir($root . '/resources/boost/skills', 0o755, recursive: true);
    file_put_contents($root . '/composer.json', json_encode(['name' => $name], JSON_THROW_ON_ERROR));

    if ($withSkill) {
        file_put_contents(
            $root . '/resources/boost/skills/sample-skill.md',
            "---\nname: sample-skill\ndescription: A sample skill.\n---\nBody.\n",
        );
    }

    return $root;
}

function makeUserScopeTempHome(): string
{
    $home = sys_get_temp_dir() . '/boost-uss-home-' . bin2hex(random_bytes(8));
    mkdir($home, 0o755, recursive: true);

    return $home;
}

/**
 * Run `$body` with `$vars` applied to the process environment, restoring
 * the prior values — including prior *absence* — afterwards. A null value
 * unsets the variable for the duration.
 *
 * @param  array<string, string|null>  $vars
 */
function withUserScopeEnv(array $vars, Closure $body): void
{
    $originals = [];
    foreach ($vars as $name => $value) {
        $originals[$name] = getenv($name);
        putenv($value === null ? $name : "{$name}={$value}");
    }

    try {
        $body();
    } finally {
        foreach ($originals as $name => $original) {
            putenv($original === false ? $name : "{$name}={$original}");
        }
    }
}

it("syncs a tool's bundled skills into the home dir and returns 0", function (): void {
    $pkg = makeUserScopeToolFixture('acme/sample-tool');
    $home = makeUserScopeTempHome();

    try {
        withUserScopeEnv(['HOME' => $home, 'BOOST_SKIP_AUTOSYNC' => null], function () use ($pkg, $home): void {
            $exit = BoostAutoSync::syncUserScope($pkg);

            expect($exit)->toBe(0)
                ->and(file_exists($home . '/.claude/skills/acme__sample-tool/sample-skill/SKILL.md'))->toBeTrue();
        });
    } finally {
        cleanupTestDir($pkg);
        cleanupTestDir($home);
    }
});

it('returns 1 when the package has no composer.json', function (): void {
    $pkg = sys_get_temp_dir() . '/boost-uss-empty-' . bin2hex(random_bytes(8));
    mkdir($pkg, 0o755, recursive: true);
    $home = makeUserScopeTempHome();

    try {
        withUserScopeEnv(['HOME' => $home, 'BOOST_SKIP_AUTOSYNC' => null], function () use ($pkg): void {
            expect(BoostAutoSync::syncUserScope($pkg))->toBe(1);
        });
    } finally {
        cleanupTestDir($pkg);
        cleanupTestDir($home);
    }
});

it('no-ops and returns 0 under BOOST_SKIP_AUTOSYNC', function (): void {
    $pkg = makeUserScopeToolFixture('acme/sample-tool');
    $home = makeUserScopeTempHome();

    try {
        withUserScopeEnv(['HOME' => $home, 'BOOST_SKIP_AUTOSYNC' => '1'], function () use ($pkg, $home): void {
            expect(BoostAutoSync::syncUserScope($pkg))->toBe(0)
                ->and(is_dir($home . '/.claude'))->toBeFalse();
        });
    } finally {
        cleanupTestDir($pkg);
        cleanupTestDir($home);
    }
});

it('runs the sync and writes the sentinel on the first syncUserScopeOnce call', function (): void {
    $pkg = makeUserScopeToolFixture('acme/sample-tool', withSkill: false);
    $xdg = sys_get_temp_dir() . '/boost-uss-xdg-' . bin2hex(random_bytes(8));

    try {
        withUserScopeEnv(['XDG_CACHE_HOME' => $xdg, 'BOOST_SKIP_AUTOSYNC' => null], function () use ($pkg, $xdg): void {
            // `$packageName` only has to be an installed package — its version
            // keys the sentinel. boost-core itself is always installed here.
            $ran = BoostAutoSync::syncUserScopeOnce($pkg, 'sandermuller/boost-core');

            // BoostAutoSync::sentinelPath sanitizes the slug — any character
            // outside `[A-Za-z0-9._@-]` becomes `-`. Branch-derived pretty
            // versions like `dev-feat/conventions-schema` contain `/`; mirror
            // the sanitization here so the test passes regardless of which
            // ref CI happens to install boost-core from.
            $prettyVersion = InstalledVersions::getPrettyVersion('sandermuller/boost-core');
            $expectedSlug = 'sandermuller-boost-core@' . preg_replace('/[^A-Za-z0-9._@-]+/', '-', (string) $prettyVersion);

            expect($ran)->toBeTrue()
                ->and(is_dir($xdg . '/boost/synced'))->toBeTrue()
                ->and(scandir($xdg . '/boost/synced'))->toContain($expectedSlug);
        });
    } finally {
        cleanupTestDir($pkg);
        cleanupTestDir($xdg);
    }
});

it('skips the sync on a second syncUserScopeOnce call once the sentinel exists', function (): void {
    $pkg = makeUserScopeToolFixture('acme/sample-tool', withSkill: false);
    $xdg = sys_get_temp_dir() . '/boost-uss-xdg-' . bin2hex(random_bytes(8));

    try {
        withUserScopeEnv(['XDG_CACHE_HOME' => $xdg, 'BOOST_SKIP_AUTOSYNC' => null], function () use ($pkg): void {
            expect(BoostAutoSync::syncUserScopeOnce($pkg, 'sandermuller/boost-core'))->toBeTrue()
                ->and(BoostAutoSync::syncUserScopeOnce($pkg, 'sandermuller/boost-core'))->toBeFalse();
        });
    } finally {
        cleanupTestDir($pkg);
        cleanupTestDir($xdg);
    }
});

it('keys the sentinel path by version — a version bump yields a fresh, unmatched path', function (): void {
    $pkg = makeUserScopeToolFixture('acme/sample-tool', withSkill: false);
    $xdg = sys_get_temp_dir() . '/boost-uss-xdg-' . bin2hex(random_bytes(8));

    try {
        withUserScopeEnv(['XDG_CACHE_HOME' => $xdg, 'BOOST_SKIP_AUTOSYNC' => null], function () use ($pkg, $xdg): void {
            BoostAutoSync::syncUserScopeOnce($pkg, 'sandermuller/boost-core');

            $version = (string) InstalledVersions::getPrettyVersion('sandermuller/boost-core');
            $expectedSlug = preg_replace('/[^A-Za-z0-9._@-]+/', '-', 'sandermuller-boost-core@' . $version);

            // The version is baked into the sentinel filename — a bumped
            // version produces a different, not-yet-present path → re-sync.
            expect($xdg . '/boost/synced/' . $expectedSlug)
                ->toBeFile();
        });
    } finally {
        cleanupTestDir($pkg);
        cleanupTestDir($xdg);
    }
});

it('no-ops, returns false, and writes no sentinel under BOOST_SKIP_AUTOSYNC', function (): void {
    $pkg = makeUserScopeToolFixture('acme/sample-tool', withSkill: false);
    $xdg = sys_get_temp_dir() . '/boost-uss-xdg-' . bin2hex(random_bytes(8));

    try {
        withUserScopeEnv(['XDG_CACHE_HOME' => $xdg, 'BOOST_SKIP_AUTOSYNC' => '1'], function () use ($pkg, $xdg): void {
            expect(BoostAutoSync::syncUserScopeOnce($pkg, 'sandermuller/boost-core'))->toBeFalse()
                ->and(is_dir($xdg . '/boost'))->toBeFalse();
        });
    } finally {
        cleanupTestDir($pkg);
        cleanupTestDir($xdg);
    }
});

it('falls the sentinel back to the system temp dir when HOME, XDG_CACHE_HOME and USERPROFILE are unset', function (): void {
    $pkg = makeUserScopeToolFixture('acme/sample-tool', withSkill: false);
    $version = (string) InstalledVersions::getPrettyVersion('sandermuller/boost-core');
    $expectedSlug = preg_replace('/[^A-Za-z0-9._@-]+/', '-', 'sandermuller-boost-core@' . $version);
    $expectedSentinel = rtrim(sys_get_temp_dir(), '/') . '/boost/synced/' . $expectedSlug;

    try {
        withUserScopeEnv(['HOME' => null, 'XDG_CACHE_HOME' => null, 'USERPROFILE' => null, 'BOOST_SKIP_AUTOSYNC' => null], function () use ($pkg, $expectedSentinel): void {
            BoostAutoSync::syncUserScopeOnce($pkg, 'sandermuller/boost-core');

            expect($expectedSentinel)
                ->toBeFile();
        });
    } finally {
        @unlink($expectedSentinel);
        @rmdir(rtrim(sys_get_temp_dir(), '/') . '/boost/synced');
        @rmdir(rtrim(sys_get_temp_dir(), '/') . '/boost');
        cleanupTestDir($pkg);
    }
});

it('keys the sentinel under %USERPROFILE%/.cache on Windows-shaped envs (HOME unset)', function (): void {
    $pkg = makeUserScopeToolFixture('acme/sample-tool', withSkill: false);
    $profile = makeUserScopeTempHome();

    try {
        withUserScopeEnv(
            ['HOME' => null, 'XDG_CACHE_HOME' => null, 'USERPROFILE' => $profile, 'BOOST_SKIP_AUTOSYNC' => null],
            function () use ($pkg, $profile): void {
                // SyncEngine writes skills under USERPROFILE on Windows; the
                // sentinel must follow it there, not land in an ephemeral temp.
                BoostAutoSync::syncUserScopeOnce($pkg, 'sandermuller/boost-core');

                expect(is_dir($profile . '/.cache/boost/synced'))->toBeTrue();
            },
        );
    } finally {
        cleanupTestDir($pkg);
        cleanupTestDir($profile);
    }
});

it('does not write the sentinel when the sync fails — so the next call retries', function (): void {
    $pkg = sys_get_temp_dir() . '/boost-uss-bad-' . bin2hex(random_bytes(8));
    mkdir($pkg, 0o755, recursive: true);
    $xdg = sys_get_temp_dir() . '/boost-uss-xdg-' . bin2hex(random_bytes(8));

    try {
        withUserScopeEnv(['XDG_CACHE_HOME' => $xdg, 'BOOST_SKIP_AUTOSYNC' => null], function () use ($pkg, $xdg): void {
            // No composer.json → the sync fails. It still ran (→ true), but the
            // sentinel must stay absent so a later invocation retries.
            expect(BoostAutoSync::syncUserScopeOnce($pkg, 'sandermuller/boost-core'))->toBeTrue()
                ->and(is_dir($xdg . '/boost'))->toBeFalse()
                ->and(BoostAutoSync::syncUserScopeOnce($pkg, 'sandermuller/boost-core'))->toBeTrue();
        });
    } finally {
        cleanupTestDir($pkg);
        cleanupTestDir($xdg);
    }
});

it('degrades to an ungated sync when the package version cannot be resolved', function (): void {
    $pkg = makeUserScopeToolFixture('acme/sample-tool', withSkill: false);
    $xdg = sys_get_temp_dir() . '/boost-uss-xdg-' . bin2hex(random_bytes(8));

    try {
        withUserScopeEnv(['XDG_CACHE_HOME' => $xdg, 'BOOST_SKIP_AUTOSYNC' => null], function () use ($pkg, $xdg): void {
            // An uninstalled package has no resolvable version → no sentinel
            // → every call re-syncs rather than being gated.
            expect(BoostAutoSync::syncUserScopeOnce($pkg, 'nonexistent/package'))->toBeTrue()
                ->and(BoostAutoSync::syncUserScopeOnce($pkg, 'nonexistent/package'))->toBeTrue()
                ->and(is_dir($xdg . '/boost'))->toBeFalse();
        });
    } finally {
        cleanupTestDir($pkg);
        cleanupTestDir($xdg);
    }
});
