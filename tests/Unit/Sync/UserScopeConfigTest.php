<?php declare(strict_types=1);

use SanderMuller\BoostCore\Sync\UserScopeConfig;

function userScopeConfigHome(?string $contents): string
{
    $home = sys_get_temp_dir() . '/boost-userscope-config-' . bin2hex(random_bytes(8));
    mkdir($home . '/.boost', 0o755, recursive: true);

    if ($contents !== null) {
        file_put_contents($home . '/.boost/user-scope.php', $contents);
    }

    return $home;
}

it('has no entries and no errors when the file is absent', function (): void {
    $config = UserScopeConfig::fromHome(userScopeConfigHome(null));

    expect($config->hasErrors())->toBeFalse()
        ->and($config->selectionFor('acme/tools'))->toBeNull();
});

it('reads the selection per package', function (): void {
    $config = UserScopeConfig::fromHome(userScopeConfigHome(<<<'PHP'
        <?php return ['skills' => [
            'acme/tools' => ['alpha', 'beta', 'alpha'],
            'acme/quiet' => [],
        ]];
        PHP));

    expect($config->hasErrors())->toBeFalse()
        ->and($config->selectionFor('acme/tools'))->toBe(['alpha', 'beta'])
        ->and($config->selectionFor('acme/quiet'))->toBe([])
        ->and($config->selectionFor('acme/not-installed-here'))->toBeNull();
});

it('accepts an empty skills map', function (): void {
    $config = UserScopeConfig::fromHome(userScopeConfigHome("<?php return ['skills' => []];"));

    expect($config->hasErrors())->toBeFalse();
});

it('reports an error for a file that throws', function (): void {
    $config = UserScopeConfig::fromHome(userScopeConfigHome("<?php throw new RuntimeException('boom');"));

    expect($config->errors())->toHaveCount(1)
        ->and($config->errors()[0])->toContain('could not be loaded: boom');
});

it('reports an error for a file with a parse error', function (): void {
    $config = UserScopeConfig::fromHome(userScopeConfigHome('<?php return [;'));

    expect($config->errors())->toHaveCount(1)
        ->and($config->errors()[0])->toContain('could not be loaded');
});

it('reports an error for a non-array return', function (): void {
    $config = UserScopeConfig::fromHome(userScopeConfigHome("<?php return 'nope';"));

    expect($config->errors()[0])->toContain('must return an array');
});

it('reports an error for an unknown top-level key', function (): void {
    $config = UserScopeConfig::fromHome(userScopeConfigHome("<?php return ['skill' => []];"));

    expect($config->errors()[0])->toContain('unknown key "skill"');
});

it('reports an error for wrong value types', function (string $php, string $message): void {
    $config = UserScopeConfig::fromHome(userScopeConfigHome($php));

    expect($config->hasErrors())->toBeTrue()
        ->and($config->errors()[0])->toContain($message)
        ->and($config->selectionFor('acme/tools'))->toBeNull();
})->with([
    'skills not an array' => ["<?php return ['skills' => 'acme/tools'];", '"skills" must be an array'],
    'skills null' => ["<?php return ['skills' => null];", '"skills" must be an array'],
    'list instead of map' => ["<?php return ['skills' => [['alpha']]];", 'keys must be Composer package names'],
    'string instead of list' => ["<?php return ['skills' => ['acme/tools' => 'alpha']];", 'must be a list of skill names'],
    'map instead of list' => ["<?php return ['skills' => ['acme/tools' => ['a' => 'alpha']]];", 'must be a list of skill names'],
    'non-string name' => ["<?php return ['skills' => ['acme/tools' => [1]]];", 'only non-empty strings'],
    'empty name' => ["<?php return ['skills' => ['acme/tools' => ['']]];", 'only non-empty strings'],
]);
