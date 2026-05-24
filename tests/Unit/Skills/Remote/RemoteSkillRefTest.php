<?php declare(strict_types=1);

use SanderMuller\BoostCore\Skills\Remote\RemoteSkillRef;

it('constructs a valid bundle-mode ref (asset set)', function (): void {
    $ref = new RemoteSkillRef('composer-upgrade', asset: 'composer-upgrade.skill');

    expect($ref->name)->toBe('composer-upgrade')
        ->and($ref->asset)->toBe('composer-upgrade.skill')
        ->and($ref->path)->toBeNull();
});

it('constructs a valid path-mode ref (path set)', function (): void {
    $ref = new RemoteSkillRef('grill-with-docs', path: 'skills/engineering/grill-with-docs');

    expect($ref->path)->toBe('skills/engineering/grill-with-docs')
        ->and($ref->asset)->toBeNull();
});

it('accepts `.` as a path (whole-repo single-skill case)', function (): void {
    $ref = new RemoteSkillRef('humanizer', path: '.');

    expect($ref->path)->toBe('.');
});

it('rejects neither asset nor path set', function (): void {
    expect(fn () => new RemoteSkillRef('foo'))
        ->toThrow(InvalidArgumentException::class, 'one of `asset` or `path` must be set');
});

it('rejects both asset and path set (exclusive modes)', function (): void {
    expect(fn () => new RemoteSkillRef('foo', asset: 'foo.skill', path: 'foo'))
        ->toThrow(InvalidArgumentException::class, 'mutually exclusive');
});

it('rejects a name with uppercase letters', function (): void {
    expect(fn () => new RemoteSkillRef('FooBar', asset: 'foo.skill'))
        ->toThrow(InvalidArgumentException::class, 'kebab/snake-case identifier');
});

it('rejects a name with a slash', function (): void {
    expect(fn () => new RemoteSkillRef('foo/bar', asset: 'foo.skill'))
        ->toThrow(InvalidArgumentException::class, 'kebab/snake-case identifier');
});

it('rejects a name with a backslash', function (): void {
    expect(fn () => new RemoteSkillRef('foo\\bar', asset: 'foo.skill'))
        ->toThrow(InvalidArgumentException::class, 'kebab/snake-case identifier');
});

it('rejects an empty name', function (): void {
    expect(fn () => new RemoteSkillRef('', asset: 'foo.skill'))
        ->toThrow(InvalidArgumentException::class, 'kebab/snake-case identifier');
});

it('rejects a name starting with a hyphen', function (): void {
    expect(fn () => new RemoteSkillRef('-foo', asset: 'foo.skill'))
        ->toThrow(InvalidArgumentException::class, 'kebab/snake-case identifier');
});

it('rejects a name ending with a hyphen', function (): void {
    expect(fn () => new RemoteSkillRef('foo-', asset: 'foo.skill'))
        ->toThrow(InvalidArgumentException::class, 'kebab/snake-case identifier');
});

it('accepts a single-character name', function (): void {
    $ref = new RemoteSkillRef('x', asset: 'x.skill');

    expect($ref->name)->toBe('x');
});

it('rejects an asset name with a forward slash', function (): void {
    expect(fn () => new RemoteSkillRef('foo', asset: 'dir/foo.skill'))
        ->toThrow(InvalidArgumentException::class, 'must not contain directory separators');
});

it('rejects an asset name with a backslash', function (): void {
    expect(fn () => new RemoteSkillRef('foo', asset: 'dir\\foo.skill'))
        ->toThrow(InvalidArgumentException::class, 'must not contain directory separators');
});

it('rejects a path containing `..` (path traversal)', function (): void {
    expect(fn () => new RemoteSkillRef('foo', path: 'foo/../bar'))
        ->toThrow(InvalidArgumentException::class, 'must not contain `..` segments');
});

it('rejects a path starting with `..`', function (): void {
    expect(fn () => new RemoteSkillRef('foo', path: '../bar'))
        ->toThrow(InvalidArgumentException::class, 'must not contain `..` segments');
});

it('rejects a path with `..` after a backslash-normalized segment', function (): void {
    expect(fn () => new RemoteSkillRef('foo', path: 'foo\\..\\bar'))
        ->toThrow(InvalidArgumentException::class, 'must not contain `..` segments');
});
