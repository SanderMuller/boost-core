<?php declare(strict_types=1);

use SanderMuller\BoostCore\Conventions\Diagnostic;
use SanderMuller\BoostCore\Sync\InstalledPackages;
use SanderMuller\BoostCore\Sync\PackageInfo;
use SanderMuller\BoostCore\Sync\SyncEngine;

/**
 * A host command and a skill with the same name both land in an agent that has
 * a command surface, so one slash name gets two entry points. Sync says so; it
 * does not pick a winner.
 */
function clashProject(string $configBody): string
{
    $root = sys_get_temp_dir() . '/boost-clash-' . bin2hex(random_bytes(8));
    mkdir($root . '/.ai/commands', 0o755, recursive: true);
    file_put_contents($root . '/.ai/commands/deploy.md', "---\ndescription: Deploy.\n---\nDeploy the app.\n");
    file_put_contents(
        $root . '/boost.php',
        "<?php\n\ndeclare(strict_types=1);\n\nuse SanderMuller\\BoostCore\\Config\\BoostConfig;\nuse SanderMuller\\BoostCore\\Enums\\Agent;\n\nreturn {$configBody};\n",
    );

    return $root;
}

function clashVendor(): string
{
    $dir = sys_get_temp_dir() . '/boost-clash-vendor-' . bin2hex(random_bytes(8));
    mkdir($dir . '/resources/boost/skills/deploy', 0o755, recursive: true);
    file_put_contents($dir . '/composer.json', json_encode(['name' => 'acme/pack'], JSON_THROW_ON_ERROR));
    file_put_contents($dir . '/resources/boost/skills/deploy/SKILL.md', "---\nname: deploy\ndescription: Deploy skill.\n---\nBody.\n");

    return $dir;
}

/**
 * @param  list<Diagnostic>  $diagnostics
 */
function clashWarnings(array $diagnostics): string
{
    $warnings = array_filter($diagnostics, static fn (Diagnostic $diagnostic): bool => $diagnostic->level === 'warning');

    return implode("\n", array_map(static fn (Diagnostic $diagnostic): string => $diagnostic->message, $warnings));
}

function clashSync(string $root, string $vendorDir, bool $checkOnly = false): string
{
    $packages = new InstalledPackages(['acme/pack' => new PackageInfo('acme/pack', '1.0.0', $vendorDir)]);

    return clashWarnings(SyncEngine::default($packages)->sync($root, $checkOnly)->diagnostics);
}

it('warns when a host command and a vendor skill share a name, naming both and the fix', function (bool $checkOnly): void {
    $root = clashProject("BoostConfig::configure()->withAgents([Agent::CLAUDE_CODE])->withAllowedVendors(['acme/pack'])");
    $vendorDir = clashVendor();

    try {
        $warnings = clashSync($root, $vendorDir, $checkOnly);

        expect($warnings)->toContain('`deploy`')
            ->and($warnings)->toContain('(.ai/commands/deploy.md)')
            ->and($warnings)->toContain('acme/pack')
            ->and($warnings)->toContain("withExcludedSkills(['acme/pack:deploy'])");

        if (! $checkOnly) {
            // Advisory only: both still land.
            expect($root . '/.claude/commands/deploy.md')->toBeFile()
                ->and($root . '/.claude/skills/deploy/SKILL.md')->toBeFile();
        }
    } finally {
        cleanupTestDir($root);
        cleanupTestDir($vendorDir);
    }
})->with(['check' => [true], 'real' => [false]]);

it('tells the operator to rename one side when the skill is host-authored', function (): void {
    $root = clashProject('BoostConfig::configure()->withAgents([Agent::CLAUDE_CODE])');
    $vendorDir = clashVendor();

    try {
        mkdir($root . '/.ai/skills/deploy', 0o755, recursive: true);
        file_put_contents($root . '/.ai/skills/deploy/SKILL.md', "---\nname: deploy\ndescription: Host skill.\n---\nBody.\n");

        $warnings = clashSync($root, $vendorDir);

        expect($warnings)->toContain('`deploy`')
            ->and($warnings)->toContain('host skill `deploy` (.ai/skills/deploy/SKILL.md)')
            ->and($warnings)->toContain('Rename')
            ->and($warnings)->not->toContain('withExcludedSkills');
    } finally {
        cleanupTestDir($root);
        cleanupTestDir($vendorDir);
    }
});

it('stays silent when the clashing vendor skill is excluded', function (): void {
    $root = clashProject("BoostConfig::configure()->withAgents([Agent::CLAUDE_CODE])->withAllowedVendors(['acme/pack'])->withExcludedSkills(['acme/pack:deploy'])");
    $vendorDir = clashVendor();

    try {
        expect(clashSync($root, $vendorDir))->not->toContain('`deploy`');
    } finally {
        cleanupTestDir($root);
        cleanupTestDir($vendorDir);
    }
});

it('stays silent when no configured agent has a command surface', function (): void {
    $root = clashProject("BoostConfig::configure()->withAgents([Agent::GEMINI])->withAllowedVendors(['acme/pack'])");
    $vendorDir = clashVendor();

    try {
        expect(clashSync($root, $vendorDir))->not->toContain('`deploy`');
    } finally {
        cleanupTestDir($root);
        cleanupTestDir($vendorDir);
    }
});

it('warns for Kiro, which emits the command over the same-name skill', function (): void {
    $root = clashProject("BoostConfig::configure()->withAgents([Agent::KIRO])->withAllowedVendors(['acme/pack'])");
    $vendorDir = clashVendor();

    try {
        expect(clashSync($root, $vendorDir))->toContain("withExcludedSkills(['acme/pack:deploy'])")
            // One path for both: the command is written last and wins.
            ->and((string) file_get_contents($root . '/.kiro/skills/deploy/SKILL.md'))->toContain('Deploy the app.')
            ->and((string) file_get_contents($root . '/.kiro/skills/deploy/SKILL.md'))->not->toContain('Body.');
    } finally {
        cleanupTestDir($root);
        cleanupTestDir($vendorDir);
    }
});
