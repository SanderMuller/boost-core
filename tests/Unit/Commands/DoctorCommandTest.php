<?php declare(strict_types=1);

use Composer\Console\Application as ComposerApplication;
use SanderMuller\BoostCore\Commands\DoctorCommand;
use Symfony\Component\Console\Tester\CommandTester;

function doctorTempProject(string $boostBody): string
{
    $dir = sys_get_temp_dir() . '/boost-doctor-' . bin2hex(random_bytes(8));
    mkdir($dir, 0o755, recursive: true);
    file_put_contents(
        $dir . '/boost.php',
        "<?php\nuse SanderMuller\\BoostCore\\Config\\BoostConfig;\nuse SanderMuller\\BoostCore\\Enums\\Agent;\nuse SanderMuller\\BoostCore\\Skills\\Remote\\RemoteSkillSource;\nreturn {$boostBody};\n",
    );

    return $dir;
}

function doctorCleanup(string $dir): void
{
    if (is_dir($dir)) {
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        /** @var SplFileInfo $f */
        foreach ($iter as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }

        @rmdir($dir);
    }
}

/**
 * @return array{exit: int, display: string}
 */
function runDoctor(string $dir, ?string $cacheRoot = null): array
{
    if ($cacheRoot !== null) {
        putenv('BOOST_CACHE_HOME=' . $cacheRoot);
    }

    $command = new DoctorCommand();
    $app = new ComposerApplication();
    $app->addCommand($command);

    $tester = new CommandTester($command);
    $exit = $tester->execute(['--working-dir' => $dir]);

    if ($cacheRoot !== null) {
        putenv('BOOST_CACHE_HOME');
    }

    return ['exit' => $exit, 'display' => $tester->getDisplay()];
}

/** Seed the cache with one resolved-ref slot containing a skill, so the doctor reports it as `cached`. */
function doctorSeedCachedSkill(string $cacheRoot, string $source, string $resolvedRef, string $skillName): void
{
    $slug = str_replace('/', '__', $source);
    $skillDir = $cacheRoot . '/boost/remote-skills/' . $slug . '/' . $resolvedRef . '/' . $skillName;
    mkdir($skillDir, 0o755, recursive: true);
    file_put_contents($skillDir . '/SKILL.md', "---\nname: {$skillName}\n---\nBody.");
}

it('doctor: omits the remote-skill section when no sources are declared', function (): void {
    $dir = doctorTempProject('BoostConfig::configure()->withAgents([Agent::CLAUDE_CODE])');
    try {
        $result = runDoctor($dir);
        // Asserting the exit code AND the title proves the absence assertion isn't passing
        // because doctor crashed before reaching the remote-skill section.
        expect($result['exit'])->toBe(0)
            ->and($result['display'])->toContain('boost-core doctor')
            ->and($result['display'])->not->toContain('Remote skill sources');
    } finally {
        doctorCleanup($dir);
    }
});

it('doctor: reports pinned vs moving refs and cache presence', function (): void {
    $cacheRoot = sys_get_temp_dir() . '/boost-doctor-cache-' . bin2hex(random_bytes(6));
    mkdir($cacheRoot, 0o755, recursive: true);

    // Pinned (v1.0.0) — pre-seed cache so it reports `cached`.
    // Moving (main) — no cache → reports `not cached`.
    doctorSeedCachedSkill($cacheRoot, 'peterfox/agent-skills', 'v1.0.0', 'composer-upgrade');

    $body = "BoostConfig::configure()->withAgents([Agent::CLAUDE_CODE])->withRemoteSkills([\n"
        . "    RemoteSkillSource::githubBundle('peterfox/agent-skills', 'v1.0.0', ['composer-upgrade']),\n"
        . "    RemoteSkillSource::githubPath('acme/skills', 'main', ['grilled' => 'skills/grilled']),\n"
        . '])';
    $dir = doctorTempProject($body);

    try {
        $result = runDoctor($dir, $cacheRoot);

        expect($result['display'])
            ->toContain('Remote skill sources')
            ->toContain('peterfox/agent-skills@v1.0.0')
            ->toContain('acme/skills@main')
            ->toContain('⚠ moving ref')
            ->toContain('cached')
            ->toContain('not cached')
            ->toContain('Moving refs re-resolve');
    } finally {
        doctorCleanup($dir);
        doctorCleanup($cacheRoot);
    }
});

it('doctor: surfaces the BOOST_GITHUB_TOKEN note above the source-count threshold', function (): void {
    // 4 sources triggers the anonymous-auth nudge.
    $body = "BoostConfig::configure()->withAgents([Agent::CLAUDE_CODE])->withRemoteSkills([\n"
        . "    RemoteSkillSource::githubBundle('a/one', 'v1', ['s']),\n"
        . "    RemoteSkillSource::githubBundle('b/two', 'v1', ['s']),\n"
        . "    RemoteSkillSource::githubBundle('c/three', 'v1', ['s']),\n"
        . "    RemoteSkillSource::githubBundle('d/four', 'v1', ['s']),\n"
        . '])';
    $dir = doctorTempProject($body);
    putenv('BOOST_GITHUB_TOKEN'); // ensure unset

    try {
        $result = runDoctor($dir);
        expect($result['display'])->toContain('BOOST_GITHUB_TOKEN');
    } finally {
        doctorCleanup($dir);
    }
});

it('doctor: prints the Codex manual-path note when commands present and Codex selected', function (): void {
    $dir = doctorTempProject('BoostConfig::configure()->withAgents([Agent::CODEX])');
    try {
        mkdir($dir . '/.ai/commands', 0o755, recursive: true);
        file_put_contents($dir . '/.ai/commands/deploy.md', "---\ndescription: Ship.\n---\n\nBody.\n");

        $result = runDoctor($dir);

        expect($result['exit'])->toBe(0)
            ->and($result['display'])->toContain('Command-emit limitations')
            ->and($result['display'])->toContain('~/.codex/prompts/')
            ->and($result['display'])->not->toContain('.gemini/commands/');
    } finally {
        doctorCleanup($dir);
    }
});

it('doctor: prints the Gemini manual-path note when commands present and Gemini selected', function (): void {
    $dir = doctorTempProject('BoostConfig::configure()->withAgents([Agent::GEMINI])');
    try {
        mkdir($dir . '/.ai/commands', 0o755, recursive: true);
        file_put_contents($dir . '/.ai/commands/deploy.md', "---\ndescription: Ship.\n---\n\nBody.\n");

        $result = runDoctor($dir);

        expect($result['exit'])->toBe(0)
            ->and($result['display'])->toContain('Command-emit limitations')
            ->and($result['display'])->toContain('.gemini/commands/')
            ->and($result['display'])->toContain('TOML')
            ->and($result['display'])->not->toContain('~/.codex/prompts/');
    } finally {
        doctorCleanup($dir);
    }
});

it('doctor: omits the command-emit limitations section when no .ai/commands/ exists', function (): void {
    $dir = doctorTempProject('BoostConfig::configure()->withAgents([Agent::CODEX, Agent::GEMINI])');
    try {
        $result = runDoctor($dir);
        expect($result['exit'])->toBe(0)
            ->and($result['display'])->not->toContain('Command-emit limitations');
    } finally {
        doctorCleanup($dir);
    }
});

it('doctor: omits the command-emit limitations section when neither Codex nor Gemini is selected', function (): void {
    $dir = doctorTempProject('BoostConfig::configure()->withAgents([Agent::CLAUDE_CODE])');
    try {
        mkdir($dir . '/.ai/commands', 0o755, recursive: true);
        file_put_contents($dir . '/.ai/commands/deploy.md', "Body.\n");

        $result = runDoctor($dir);
        expect($result['exit'])->toBe(0)
            ->and($result['display'])->not->toContain('Command-emit limitations');
    } finally {
        doctorCleanup($dir);
    }
});

it('doctor: prints both notes when commands present and both Codex AND Gemini selected', function (): void {
    $dir = doctorTempProject('BoostConfig::configure()->withAgents([Agent::CODEX, Agent::GEMINI])');
    try {
        mkdir($dir . '/.ai/commands', 0o755, recursive: true);
        file_put_contents($dir . '/.ai/commands/deploy.md', "Body.\n");

        $result = runDoctor($dir);
        expect($result['exit'])->toBe(0)
            ->and($result['display'])->toContain('Command-emit limitations')
            ->and($result['display'])->toContain('~/.codex/prompts/')
            ->and($result['display'])->toContain('.gemini/commands/');
    } finally {
        doctorCleanup($dir);
    }
});

it('doctor: ignores non-.md files in .ai/commands/ for limitation triggering', function (): void {
    // Regression guard — only `.md` is a Phase 1 command source. A stray
    // `.txt` / `.bak` / dotfile in `.ai/commands/` must NOT trigger the
    // limitations section, since it isn't going to be emitted anyway.
    $dir = doctorTempProject('BoostConfig::configure()->withAgents([Agent::CODEX])');
    try {
        mkdir($dir . '/.ai/commands', 0o755, recursive: true);
        file_put_contents($dir . '/.ai/commands/notes.txt', "Not a command.\n");
        file_put_contents($dir . '/.ai/commands/.DS_Store', '');

        $result = runDoctor($dir);
        expect($result['exit'])->toBe(0)
            ->and($result['display'])->not->toContain('Command-emit limitations');
    } finally {
        doctorCleanup($dir);
    }
});

it('doctor: omits the limitations section for Kiro alone — Kiro has a native (skill-shaped) command emit', function (): void {
    // Regression guard: Kiro is intentionally NOT in the limitations
    // list, because KiroTarget::planCommands() emits each command as
    // a `.kiro/skills/<name>/SKILL.md`. A future regression that mistakenly
    // adds Kiro to the manual-path lines would tell users to author Kiro
    // commands manually even though boost-core syncs them.
    $dir = doctorTempProject('BoostConfig::configure()->withAgents([Agent::KIRO])');
    try {
        mkdir($dir . '/.ai/commands', 0o755, recursive: true);
        file_put_contents($dir . '/.ai/commands/deploy.md', "Body.\n");

        $result = runDoctor($dir);
        expect($result['exit'])->toBe(0)
            ->and($result['display'])->not->toContain('Command-emit limitations');
    } finally {
        doctorCleanup($dir);
    }
});

it('doctor: surfaces the limitations note when a nested command (.ai/commands/sub/deploy.md) exists', function (): void {
    // Regression for code-review finding #2 — doctor must mirror
    // CommandLoader's recursive Finder scan, not a one-level scandir.
    // A command in a subdirectory IS emitted by sync; doctor must agree.
    $dir = doctorTempProject('BoostConfig::configure()->withAgents([Agent::CODEX])');
    try {
        mkdir($dir . '/.ai/commands/sub', 0o755, recursive: true);
        file_put_contents($dir . '/.ai/commands/sub/deploy.md', "Body.\n");

        $result = runDoctor($dir);
        expect($result['exit'])->toBe(0)
            ->and($result['display'])->toContain('Command-emit limitations')
            ->and($result['display'])->toContain('~/.codex/prompts/');
    } finally {
        doctorCleanup($dir);
    }
});
