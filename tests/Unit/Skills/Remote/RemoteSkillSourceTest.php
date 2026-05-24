<?php declare(strict_types=1);

use SanderMuller\BoostCore\Skills\Remote\RemoteSkillRef;
use SanderMuller\BoostCore\Skills\Remote\RemoteSkillSource;

it('constructs a valid bundle source via the value-object API', function (): void {
    $source = new RemoteSkillSource('peterfox/agent-skills', 'v1.2.0', [
        new RemoteSkillRef('composer-upgrade', asset: 'composer-upgrade.skill'),
    ]);

    expect($source->source)->toBe('peterfox/agent-skills')
        ->and($source->version)->toBe('v1.2.0')
        ->and($source->skills)->toHaveCount(1)
        ->and($source->mode())->toBe(RemoteSkillSource::MODE_BUNDLE);
});

it('constructs a valid path source via the value-object API', function (): void {
    $source = new RemoteSkillSource('mattpocock/skills', 'main', [
        new RemoteSkillRef('grill-with-docs', path: 'skills/engineering/grill-with-docs'),
    ]);

    expect($source->mode())->toBe(RemoteSkillSource::MODE_PATH);
});

it('rejects an empty skills list', function (): void {
    expect(fn () => new RemoteSkillSource('a/b', 'v1', []))
        ->toThrow(InvalidArgumentException::class, 'skills list is empty');
});

it('rejects a source that does not match `<owner>/<repo>`', function (): void {
    expect(fn () => new RemoteSkillSource('no-slash', 'v1', [new RemoteSkillRef('foo', asset: 'foo.skill')]))
        ->toThrow(InvalidArgumentException::class, '<owner>/<repo>');
});

it('rejects a source with three slash-separated segments', function (): void {
    expect(fn () => new RemoteSkillSource('a/b/c', 'v1', [new RemoteSkillRef('foo', asset: 'foo.skill')]))
        ->toThrow(InvalidArgumentException::class, '<owner>/<repo>');
});

it('rejects an empty version', function (): void {
    expect(fn () => new RemoteSkillSource('a/b', '', [new RemoteSkillRef('foo', asset: 'foo.skill')]))
        ->toThrow(InvalidArgumentException::class, 'version is required');
});

it('rejects duplicate skill names within one source', function (): void {
    expect(fn () => new RemoteSkillSource('a/b', 'v1', [
        new RemoteSkillRef('foo', asset: 'foo.skill'),
        new RemoteSkillRef('foo', asset: 'foo-alt.skill'),
    ]))->toThrow(InvalidArgumentException::class, 'duplicate skill name `foo`');
});

it('rejects mixed bundle + path skills within one source', function (): void {
    expect(fn () => new RemoteSkillSource('a/b', 'main', [
        new RemoteSkillRef('foo', asset: 'foo.skill'),
        new RemoteSkillRef('bar', path: 'bar'),
    ]))->toThrow(InvalidArgumentException::class, 'cannot mix `asset` (bundle) and `path` skills');
});

it('rejects duplicate paths in a path source', function (): void {
    expect(fn () => new RemoteSkillSource('a/b', 'main', [
        new RemoteSkillRef('first', path: 'shared/dir'),
        new RemoteSkillRef('second', path: 'shared/dir'),
    ]))->toThrow(InvalidArgumentException::class, 'duplicate path `shared/dir`');
});

it('githubBundle factory builds default `<name>.skill` assets', function (): void {
    $source = RemoteSkillSource::githubBundle('peterfox/agent-skills', 'v1.2.0', [
        'composer-upgrade',
        'phpstan-developer',
    ]);

    expect($source->skills)->toHaveCount(2)
        ->and($source->skills[0]->name)->toBe('composer-upgrade')
        ->and($source->skills[0]->asset)->toBe('composer-upgrade.skill')
        ->and($source->skills[1]->name)->toBe('phpstan-developer')
        ->and($source->skills[1]->asset)->toBe('phpstan-developer.skill')
        ->and($source->mode())->toBe(RemoteSkillSource::MODE_BUNDLE);
});

it('githubBundle accepts `latest` as version', function (): void {
    $source = RemoteSkillSource::githubBundle('a/b', 'latest', ['foo']);

    expect($source->version)->toBe('latest');
});

it('githubBundle rejects `main` (branch name — release assets are tag-anchored)', function (): void {
    expect(fn () => RemoteSkillSource::githubBundle('a/b', 'main', ['foo']))
        ->toThrow(InvalidArgumentException::class, 'looks like a branch name');
});

it('githubBundle rejects `master` (default-branch heuristic)', function (): void {
    expect(fn () => RemoteSkillSource::githubBundle('a/b', 'master', ['foo']))
        ->toThrow(InvalidArgumentException::class, 'looks like a branch name');
});

it('githubBundle rejects `develop` and `dev` and `trunk`', function (): void {
    foreach (['develop', 'dev', 'trunk'] as $branch) {
        expect(fn () => RemoteSkillSource::githubBundle('a/b', $branch, ['foo']))
            ->toThrow(InvalidArgumentException::class, 'looks like a branch name');
    }
});

it('githubBundle rejects a bare-SHA version', function (): void {
    expect(fn () => RemoteSkillSource::githubBundle('a/b', 'abc1234def5678', ['foo']))
        ->toThrow(InvalidArgumentException::class, 'looks like a Git SHA');
});

it('githubBundle rejects a 40-char hex SHA', function (): void {
    expect(fn () => RemoteSkillSource::githubBundle('a/b', str_repeat('a', 40), ['foo']))
        ->toThrow(InvalidArgumentException::class, 'looks like a Git SHA');
});

it('githubBundle accepts a tag-shaped version', function (): void {
    foreach (['v1.2.0', '1.2.0', 'v1.0.0-beta.1', 'release-2026-05'] as $tag) {
        $source = RemoteSkillSource::githubBundle('a/b', $tag, ['foo']);
        expect($source->version)->toBe($tag);
    }
});

it('githubPath builds path-mode refs from a name=>path map', function (): void {
    $source = RemoteSkillSource::githubPath('mattpocock/skills', 'main', [
        'grill-with-docs' => 'skills/engineering/grill-with-docs',
        'humanizer' => '.',
    ]);

    expect($source->skills)->toHaveCount(2)
        ->and($source->skills[0]->name)->toBe('grill-with-docs')
        ->and($source->skills[0]->path)->toBe('skills/engineering/grill-with-docs')
        ->and($source->skills[1]->name)->toBe('humanizer')
        ->and($source->skills[1]->path)->toBe('.')
        ->and($source->mode())->toBe(RemoteSkillSource::MODE_PATH);
});

it('githubPath accepts any version form (branch / tag / SHA / `latest`)', function (): void {
    foreach (['main', 'v1.2.0', str_repeat('a', 40), 'latest'] as $version) {
        $source = RemoteSkillSource::githubPath('a/b', $version, ['foo' => 'foo']);
        expect($source->version)->toBe($version);
    }
});

it('uniqueKey() is `<source>@<version>:<mode>`', function (): void {
    $bundle = RemoteSkillSource::githubBundle('peterfox/agent-skills', 'v1.2.0', ['foo']);
    $path = RemoteSkillSource::githubPath('peterfox/agent-skills', 'v1.2.0', ['foo' => '.']);

    expect($bundle->uniqueKey())->toBe('peterfox/agent-skills@v1.2.0:bundle')
        ->and($path->uniqueKey())->toBe('peterfox/agent-skills@v1.2.0:path')
        ->and($bundle->uniqueKey())->not->toBe($path->uniqueKey());
});
