<?php declare(strict_types=1);

use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Config\BoostConfigWriteException;
use SanderMuller\BoostCore\Config\ConfigScaffolder;
use SanderMuller\BoostCore\Config\RemoteSkillsWriter;
use SanderMuller\BoostCore\Skills\Remote\RemoteSkillSource;

/**
 * `withRemoteSkills(...)` editing (spec phase 4).
 */
function remoteWriterProject(?string $body = null): string
{
    $dir = sys_get_temp_dir() . '/boost-writer-remote-' . bin2hex(random_bytes(6));
    mkdir($dir, 0o755, recursive: true);

    if ($body === null) {
        ConfigScaffolder::scaffold($dir . '/boost.php');
    } else {
        file_put_contents($dir . '/boost.php', $body);
    }

    return $dir;
}

it('writes the first entry into a freshly scaffolded config, using the short class name', function (): void {
    $dir = remoteWriterProject();

    try {
        $written = (new RemoteSkillsWriter())->write(
            $dir . '/boost.php',
            RemoteSkillSource::githubPath('mattpocock/skills', 'latest', ['grill-with-docs' => 'skills/engineering/grill-with-docs']),
        );

        expect($written)->toContain("RemoteSkillSource::githubPath('mattpocock/skills', 'latest', [")
            ->and($written)->toContain("'grill-with-docs' => 'skills/engineering/grill-with-docs',")
            // The starter imports the class, so no fully-qualified name leaks in.
            ->and($written)->not->toContain(RemoteSkillSource::class . '::')
            // Untouched parts of the file survive byte-for-byte.
            ->and($written)->toContain('Docs: https://github.com/sandermuller/boost-core')
            ->and($written)->toContain('// ->withTags([Tag::Php, Tag::Laravel])');

        $config = BoostConfig::load($dir);

        expect($config->remoteSkills)->toHaveCount(1)
            ->and($config->remoteSkills[0]->source)->toBe('mattpocock/skills')
            ->and($config->remoteSkills[0]->version)->toBe('latest')
            ->and($config->remoteSkills[0]->mode())->toBe('path');
    } finally {
        cleanupTestDir($dir);
    }
});

it('renders bundle entries as a plain name list', function (): void {
    $dir = remoteWriterProject();

    try {
        $written = (new RemoteSkillsWriter())->write(
            $dir . '/boost.php',
            RemoteSkillSource::githubBundle('peterfox/agent-skills', 'v1.2.0', ['composer-upgrade', 'phpstan-developer']),
        );

        expect($written)->toContain("RemoteSkillSource::githubBundle('peterfox/agent-skills', 'v1.2.0', [")
            ->and($written)->toContain("'composer-upgrade',")
            ->and(BoostConfig::load($dir)->remoteSkills[0]->mode())->toBe('bundle');
    } finally {
        cleanupTestDir($dir);
    }
});

it('replaces the matching entry in place, matching on source and mode but not version', function (): void {
    $dir = remoteWriterProject(<<<'PHP'
        <?php declare(strict_types=1);

        use SanderMuller\BoostCore\Config\BoostConfig;
        use SanderMuller\BoostCore\Skills\Remote\RemoteSkillSource;

        return BoostConfig::configure()
            ->withAgents([])
            ->withRemoteSkills([
                RemoteSkillSource::githubPath('other/repo', 'main', ['keep' => 'skills/keep']),
                RemoteSkillSource::githubPath('acme/skills', 'v1.2.0', ['alpha' => 'skills/alpha']),
            ]);

        PHP);

    try {
        // A different version for the same (source, mode) must UPDATE, not append —
        // two entries for one repo is the collision the ingester rejects.
        (new RemoteSkillsWriter())->write(
            $dir . '/boost.php',
            RemoteSkillSource::githubPath('acme/skills', 'v2.0.0', ['alpha' => 'skills/alpha', 'beta' => 'skills/beta']),
        );

        $config = BoostConfig::load($dir);
        $acme = array_values(array_filter(
            $config->remoteSkills,
            static fn (RemoteSkillSource $entry): bool => $entry->source === 'acme/skills',
        ));

        expect($config->remoteSkills)->toHaveCount(2)
            ->and($acme)->toHaveCount(1)
            ->and($acme[0]->version)->toBe('v2.0.0')
            ->and($acme[0]->skills)->toHaveCount(2)
            // The sibling entry is untouched.
            ->and($config->remoteSkills[0]->source)->toBe('other/repo');
    } finally {
        cleanupTestDir($dir);
    }
});

it('appends rather than replaces when the same repo is declared in the other mode', function (): void {
    $dir = remoteWriterProject(<<<'PHP'
        <?php declare(strict_types=1);

        use SanderMuller\BoostCore\Config\BoostConfig;
        use SanderMuller\BoostCore\Skills\Remote\RemoteSkillSource;

        return BoostConfig::configure()
            ->withAgents([])
            ->withRemoteSkills([
                RemoteSkillSource::githubPath('acme/skills', 'main', ['alpha' => 'skills/alpha']),
            ]);

        PHP);

    try {
        (new RemoteSkillsWriter())->write(
            $dir . '/boost.php',
            RemoteSkillSource::githubBundle('acme/skills', 'v1.0.0', ['beta']),
        );

        expect(BoostConfig::load($dir)->remoteSkills)->toHaveCount(2);
    } finally {
        cleanupTestDir($dir);
    }
});

it('removes one entry and leaves the rest of the array intact', function (): void {
    $dir = remoteWriterProject(<<<'PHP'
        <?php declare(strict_types=1);

        use SanderMuller\BoostCore\Config\BoostConfig;
        use SanderMuller\BoostCore\Skills\Remote\RemoteSkillSource;

        return BoostConfig::configure()
            ->withAgents([])
            ->withRemoteSkills([
                RemoteSkillSource::githubPath('other/repo', 'main', ['keep' => 'skills/keep']),
                RemoteSkillSource::githubPath('acme/skills', 'main', ['alpha' => 'skills/alpha']),
            ]);

        PHP);

    try {
        (new RemoteSkillsWriter())->remove($dir . '/boost.php', 'acme/skills', 'path');

        $config = BoostConfig::load($dir);

        expect($config->remoteSkills)->toHaveCount(1)
            ->and($config->remoteSkills[0]->source)->toBe('other/repo');
    } finally {
        cleanupTestDir($dir);
    }
});

it('splices out withRemoteSkills entirely when the last entry goes', function (): void {
    $dir = remoteWriterProject(<<<'PHP'
        <?php declare(strict_types=1);

        use SanderMuller\BoostCore\Config\BoostConfig;
        use SanderMuller\BoostCore\Skills\Remote\RemoteSkillSource;

        return BoostConfig::configure()
            ->withAgents([])
            ->withRemoteSkills([
                RemoteSkillSource::githubPath('acme/skills', 'main', ['alpha' => 'skills/alpha']),
            ]);

        PHP);

    try {
        $written = (new RemoteSkillsWriter())->remove($dir . '/boost.php', 'acme/skills', 'path');

        expect($written)->not->toContain('withRemoteSkills')
            ->and(BoostConfig::load($dir)->remoteSkills)
            ->toBeEmpty();
    } finally {
        cleanupTestDir($dir);
    }
});

it('treats removing a source that is not declared as a no-op', function (): void {
    $dir = remoteWriterProject();
    $before = (string) file_get_contents($dir . '/boost.php');

    try {
        $after = (new RemoteSkillsWriter())->remove($dir . '/boost.php', 'nobody/nothing', 'path');

        expect($after)->toBe($before);
    } finally {
        cleanupTestDir($dir);
    }
});

it('falls back to a fully-qualified name when the config does not import the class', function (): void {
    $dir = remoteWriterProject(<<<'PHP'
        <?php declare(strict_types=1);

        use SanderMuller\BoostCore\Config\BoostConfig;

        return BoostConfig::configure()
            ->withAgents([])
            ->withRemoteSkills([]);

        PHP);

    try {
        $written = (new RemoteSkillsWriter())->write(
            $dir . '/boost.php',
            RemoteSkillSource::githubPath('acme/skills', 'main', ['alpha' => 'skills/alpha']),
        );

        expect($written)->toContain(RemoteSkillSource::class . '::githubPath')
            ->and(BoostConfig::load($dir)->remoteSkills)->toHaveCount(1);
    } finally {
        cleanupTestDir($dir);
    }
});

it('honours an aliased import', function (): void {
    $dir = remoteWriterProject(<<<'PHP'
        <?php declare(strict_types=1);

        use SanderMuller\BoostCore\Config\BoostConfig;
        use SanderMuller\BoostCore\Skills\Remote\RemoteSkillSource as Source;

        return BoostConfig::configure()
            ->withAgents([])
            ->withRemoteSkills([
                Source::githubPath('acme/skills', 'main', ['alpha' => 'skills/alpha']),
            ]);

        PHP);

    try {
        $written = (new RemoteSkillsWriter())->write(
            $dir . '/boost.php',
            RemoteSkillSource::githubPath('acme/skills', 'v2.0.0', ['alpha' => 'skills/alpha']),
        );

        expect($written)->toContain("Source::githubPath('acme/skills', 'v2.0.0'")
            ->and(BoostConfig::load($dir)->remoteSkills)->toHaveCount(1);
    } finally {
        cleanupTestDir($dir);
    }
});

it('refuses to rewrite an array holding a raw constructor, and writes nothing', function (): void {
    $dir = remoteWriterProject(<<<'PHP'
        <?php declare(strict_types=1);

        use SanderMuller\BoostCore\Config\BoostConfig;
        use SanderMuller\BoostCore\Skills\Remote\RemoteSkillRef;
        use SanderMuller\BoostCore\Skills\Remote\RemoteSkillSource;

        return BoostConfig::configure()
            ->withAgents([])
            ->withRemoteSkills([
                new RemoteSkillSource('acme/skills', 'v1.0.0', [new RemoteSkillRef(name: 'alpha', asset: 'custom.skill')]),
            ]);

        PHP);
    $before = (string) file_get_contents($dir . '/boost.php');

    try {
        expect(fn () => (new RemoteSkillsWriter())->write(
            $dir . '/boost.php',
            RemoteSkillSource::githubPath('other/repo', 'main', ['beta' => 'skills/beta']),
        ))->toThrow(BoostConfigWriteException::class, 'not a literal');

        // Fail-closed: the hand-authored entry is still exactly as it was.
        expect((string) file_get_contents($dir . '/boost.php'))->toBe($before);
    } finally {
        cleanupTestDir($dir);
    }
});

it('names the offending line and hands back a snippet to paste', function (): void {
    $dir = remoteWriterProject(<<<'PHP'
        <?php declare(strict_types=1);

        use SanderMuller\BoostCore\Config\BoostConfig;

        $extra = [];

        return BoostConfig::configure()
            ->withAgents([])
            ->withRemoteSkills([
                ...$extra,
            ]);

        PHP);

    try {
        try {
            (new RemoteSkillsWriter())->write(
                $dir . '/boost.php',
                RemoteSkillSource::githubBundle('acme/skills', 'v1.0.0', ['alpha']),
            );
            $message = '';
        } catch (BoostConfigWriteException $exception) {
            $message = $exception->getMessage();
        }

        expect($message)->toContain('line 10')
            ->and($message)->toContain("githubBundle('acme/skills', 'v1.0.0', ['alpha'])");
    } finally {
        cleanupTestDir($dir);
    }
});

it('refuses a config whose chain is not the canonical BoostConfig::configure() shape', function (): void {
    $dir = remoteWriterProject("<?php declare(strict_types=1);\n\nreturn ['not' => 'a chain'];\n");

    try {
        expect(fn () => (new RemoteSkillsWriter())->write(
            $dir . '/boost.php',
            RemoteSkillSource::githubPath('acme/skills', 'main', ['alpha' => 'skills/alpha']),
        ))->toThrow(BoostConfigWriteException::class, 'BoostConfig::configure()');
    } finally {
        cleanupTestDir($dir);
    }
});

it('rejects a branch as a bundle version before anything is written', function (): void {
    // The value object is the gate: a branch can't address a release asset, so
    // the command can't even build the argument writeRemoteSkill() takes.
    expect(fn () => RemoteSkillSource::githubBundle('acme/skills', 'main', ['alpha']))
        ->toThrow(InvalidArgumentException::class, 'looks like a branch name');
});

it('leaves the file untouched in dry-run mode', function (): void {
    $dir = remoteWriterProject();
    $before = (string) file_get_contents($dir . '/boost.php');

    try {
        $preview = (new RemoteSkillsWriter())->write(
            $dir . '/boost.php',
            RemoteSkillSource::githubPath('acme/skills', 'main', ['alpha' => 'skills/alpha']),
            dryRun: true,
        );

        expect($preview)->toContain("githubPath('acme/skills'")
            ->and((string) file_get_contents($dir . '/boost.php'))->toBe($before);
    } finally {
        cleanupTestDir($dir);
    }
});
