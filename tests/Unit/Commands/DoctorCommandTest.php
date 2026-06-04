<?php declare(strict_types=1);

use Composer\Console\Application as ComposerApplication;
use SanderMuller\BoostCore\Commands\DoctorCommand;
use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Discovery\PackagistVersionLookup;
use SanderMuller\BoostCore\Skills\Remote\HttpResponse;
use SanderMuller\BoostCore\Sync\InstalledPackages;
use SanderMuller\BoostCore\Sync\PackageInfo;
use SanderMuller\BoostCore\Tests\Doubles\Remote\FakeHttpTransport;
use Symfony\Component\Console\Tester\CommandTester;

function doctorTempProject(string $boostBody): string
{
    $dir = sys_get_temp_dir() . '/boost-doctor-' . bin2hex(random_bytes(8));
    mkdir($dir, 0o755, recursive: true);
    // PathRepoDetector resolves `$projectRoot/vendor/` via realpath() and
    // returns empty if that directory doesn't exist — so tests asserting
    // the --check-versions path-repo flow need a vendor/ stub here.
    mkdir($dir . '/vendor', 0o755, recursive: true);
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

/**
 * @return array{exit: int, display: string}
 */
function runDoctorWithOption(string $dir, string $option): array
{
    $command = new DoctorCommand();
    $app = new ComposerApplication();
    $app->addCommand($command);

    $tester = new CommandTester($command);
    $exit = $tester->execute(['--working-dir' => $dir, $option => true]);

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

it('doctor --check-versions: omits section by default (offline-only) and adds it when the flag is passed', function (): void {
    // Routine `boost doctor` stays fully offline — the path-repo section
    // only renders behind the explicit opt-in. Inject an empty
    // InstalledPackages so the assertion holds regardless of the
    // boost-core working-dir test runner's own installed family packages
    // (which DO live outside the temp project's vendor/ and would be
    // flagged with the real fromComposer() reader).
    $dir = doctorTempProject('BoostConfig::configure()->withAgents([Agent::CLAUDE_CODE])');
    try {
        $offline = runDoctor($dir);
        expect($offline['exit'])->toBe(0)
            ->and($offline['display'])->not->toContain('Path-repo version check');

        $command = new DoctorCommand(
            injectedPackages: new InstalledPackages([]),
        );
        $app = new ComposerApplication();
        $app->addCommand($command);
        $tester = new CommandTester($command);
        $exit = $tester->execute(['--working-dir' => $dir, '--check-versions' => true]);

        expect($exit)->toBe(0)
            ->and($tester->getDisplay())->toContain('Path-repo version check')
            ->and($tester->getDisplay())->toContain('No family packages installed from a path repo.');
    } finally {
        doctorCleanup($dir);
    }
});

it('doctor --check-versions: flags a shadowed family package with the ⚠ Packagist newer indicator', function (): void {
    // Happy path the unit-test trio could not reach without DI on
    // DoctorCommand. Wire an InstalledPackages fake with a sibling-
    // pathed family package + a FakeHttpTransport returning a newer
    // Packagist version → assert the row + flag.
    $dir = doctorTempProject('BoostConfig::configure()->withAgents([Agent::CLAUDE_CODE])');
    $sibling = sys_get_temp_dir() . '/sibling-boost-core-' . bin2hex(random_bytes(8));
    mkdir($sibling, 0o755, recursive: true);

    try {
        $fakePackages = new InstalledPackages([
            'sandermuller/boost-core' => new PackageInfo(
                name: 'sandermuller/boost-core',
                version: '0.7.0',
                installPath: $sibling,
            ),
        ]);

        $url = 'https://repo.packagist.org/p2/sandermuller/boost-core.json';
        $body = (string) json_encode([
            'packages' => [
                'sandermuller/boost-core' => [
                    ['version' => '0.7.1', 'version_normalized' => '0.7.1.0'],
                ],
            ],
        ]);
        $fakeTransport = (new FakeHttpTransport())
            ->expect($url, new HttpResponse(200, $body, [], $url));

        $command = new DoctorCommand(
            packagist: new PackagistVersionLookup($fakeTransport),
            injectedPackages: $fakePackages,
        );
        $app = new ComposerApplication();
        $app->addCommand($command);
        $tester = new CommandTester($command);
        $exit = $tester->execute(['--working-dir' => $dir, '--check-versions' => true]);

        $display = $tester->getDisplay();
        expect($exit)->toBe(0)
            ->and($display)->toContain('Path-repo version check')
            ->and($display)->toContain('sandermuller/boost-core')
            ->and($display)->toContain('0.7.0')
            ->and($display)->toContain('0.7.1')
            ->and($display)->toContain('Packagist newer');
    } finally {
        doctorCleanup($dir);
        @rmdir($sibling);
    }
});

it('doctor --check-versions: no ⚠ when installed equals Packagist latest stable', function (): void {
    $dir = doctorTempProject('BoostConfig::configure()->withAgents([Agent::CLAUDE_CODE])');
    $sibling = sys_get_temp_dir() . '/sibling-boost-core-' . bin2hex(random_bytes(8));
    mkdir($sibling, 0o755, recursive: true);

    try {
        $fakePackages = new InstalledPackages([
            'sandermuller/boost-core' => new PackageInfo(
                name: 'sandermuller/boost-core',
                version: '0.7.1',
                installPath: $sibling,
            ),
        ]);

        $url = 'https://repo.packagist.org/p2/sandermuller/boost-core.json';
        $body = (string) json_encode([
            'packages' => [
                'sandermuller/boost-core' => [
                    ['version' => '0.7.1', 'version_normalized' => '0.7.1.0'],
                ],
            ],
        ]);
        $fakeTransport = (new FakeHttpTransport())
            ->expect($url, new HttpResponse(200, $body, [], $url));

        $command = new DoctorCommand(
            packagist: new PackagistVersionLookup($fakeTransport),
            injectedPackages: $fakePackages,
        );
        $app = new ComposerApplication();
        $app->addCommand($command);
        $tester = new CommandTester($command);
        $exit = $tester->execute(['--working-dir' => $dir, '--check-versions' => true]);

        $display = $tester->getDisplay();
        expect($exit)->toBe(0)
            ->and($display)->toContain('Path-repo version check')
            ->and($display)->not->toContain('Packagist newer');
    } finally {
        doctorCleanup($dir);
        @rmdir($sibling);
    }
});

it('doctor --check-versions: surfaces "lookup failed" without ⚠ when Packagist call returns null', function (): void {
    $dir = doctorTempProject('BoostConfig::configure()->withAgents([Agent::CLAUDE_CODE])');
    $sibling = sys_get_temp_dir() . '/sibling-boost-core-' . bin2hex(random_bytes(8));
    mkdir($sibling, 0o755, recursive: true);

    try {
        $fakePackages = new InstalledPackages([
            'sandermuller/boost-core' => new PackageInfo(
                name: 'sandermuller/boost-core',
                version: '0.7.0',
                installPath: $sibling,
            ),
        ]);

        $url = 'https://repo.packagist.org/p2/sandermuller/boost-core.json';
        $fakeTransport = (new FakeHttpTransport())
            ->expect($url, new HttpResponse(503, '', [], $url));

        $command = new DoctorCommand(
            packagist: new PackagistVersionLookup($fakeTransport),
            injectedPackages: $fakePackages,
        );
        $app = new ComposerApplication();
        $app->addCommand($command);
        $tester = new CommandTester($command);
        $exit = $tester->execute(['--working-dir' => $dir, '--check-versions' => true]);

        $display = $tester->getDisplay();
        expect($exit)->toBe(0)
            ->and($display)->toContain('lookup failed')
            ->and($display)->not->toContain('Packagist newer');
    } finally {
        doctorCleanup($dir);
        @rmdir($sibling);
    }
});

it('0.10.0 entry-point mismatch: emits banner-warning when project-boost-laravel is installed and doctor runs via bare CLI', function (): void {
    // The wrong-entry-point detection: if sandermuller/project-boost-laravel
    // is in the installed package set + boost doctor is invoked (via bare
    // CLI by construction — the wrapper has its own command surfaces),
    // emit the cross-agent-capability-asymmetry banner.
    $dir = doctorTempProject('BoostConfig::configure()->withAgents([Agent::CLAUDE_CODE])');
    $sibling = sys_get_temp_dir() . '/sibling-pbl-' . bin2hex(random_bytes(8));
    mkdir($sibling, 0o755, recursive: true);

    try {
        $fakePackages = new InstalledPackages([
            'sandermuller/project-boost-laravel' => new PackageInfo(
                name: 'sandermuller/project-boost-laravel',
                version: '0.3.6',
                installPath: $sibling,
            ),
        ]);

        $command = new DoctorCommand(
            injectedPackages: $fakePackages,
        );
        $app = new ComposerApplication();
        $app->addCommand($command);
        $tester = new CommandTester($command);
        $exit = $tester->execute(['--working-dir' => $dir]);

        // Strip Symfony's hard-wraps from the WARNING block — banner-long
        // messages get split mid-phrase, which breaks naive substring checks.
        $display = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
        expect($exit)->toBe(0)
            ->and($display)->toContain('Entry-point mismatch')
            ->and($display)->toContain('project-boost-laravel')
            ->and($display)->toContain('cross-agent capability asymmetry')
            ->and($display)->toContain('php artisan project-boost:sync');
    } finally {
        doctorCleanup($dir);
        @rmdir($sibling);
    }
});

it('0.10.0 entry-point mismatch: omits banner when project-boost-laravel is NOT installed (non-Laravel projects unaffected)', function (): void {
    $dir = doctorTempProject('BoostConfig::configure()->withAgents([Agent::CLAUDE_CODE])');
    try {
        $command = new DoctorCommand(
            injectedPackages: new InstalledPackages([]),
        );
        $app = new ComposerApplication();
        $app->addCommand($command);
        $tester = new CommandTester($command);
        $exit = $tester->execute(['--working-dir' => $dir]);

        expect($exit)->toBe(0)
            ->and($tester->getDisplay())->not->toContain('Entry-point mismatch')
            ->and($tester->getDisplay())->not->toContain('cross-agent capability asymmetry');
    } finally {
        doctorCleanup($dir);
    }
});

it('0.10.1 --check-stale-paths: omits section by default (opt-in only)', function (): void {
    // Default `boost doctor` invocation must not render the audit section —
    // routine usage stays focused on drift + allowlist. Stale-paths audit
    // is legacy-migration-triage, opt-in via the flag.
    $dir = doctorTempProject('BoostConfig::configure()->withAgents([Agent::COPILOT])');
    // Create a retired path on disk so the section, if it WERE to fire,
    // would have content. Asserting the section absence is then strict.
    mkdir($dir . '/.github', 0o755, recursive: true);
    file_put_contents($dir . '/.github/copilot-instructions.md', 'legacy');
    try {
        $result = runDoctor($dir);
        expect($result['exit'])->toBe(0)
            ->and($result['display'])->not->toContain('Stale paths');
    } finally {
        doctorCleanup($dir);
    }
});

it('0.10.1 --check-stale-paths: lists retired-registry paths present on disk when Copilot active', function (): void {
    // Operator upgraded past the 0.9.0 / 0.9.1 retirement boundaries with
    // Copilot still in the active agent set. The legacy files on disk
    // should surface — exactly the paths sync's cleanup pass will delete
    // on the next run. Read-only here.
    $dir = doctorTempProject('BoostConfig::configure()->withAgents([Agent::COPILOT])');
    mkdir($dir . '/.github/skills', 0o755, recursive: true);
    file_put_contents($dir . '/.github/copilot-instructions.md', 'legacy 0.9.0 file');
    file_put_contents($dir . '/.github/skills/legacy-skill.md', 'legacy 0.9.1 file');
    try {
        $result = runDoctorWithOption($dir, '--check-stale-paths');
        $display = preg_replace('/\s+/', ' ', $result['display']) ?? '';
        expect($result['exit'])->toBe(0)
            ->and($display)->toContain('Stale paths')
            ->and($display)->toContain('.github/copilot-instructions.md')
            ->and($display)->toContain('.github/skills')
            ->and($display)->toContain('Next `vendor/bin/boost sync` will delete');
    } finally {
        doctorCleanup($dir);
    }
});

it('0.10.1 --check-stale-paths: clean message when registry paths are absent', function (): void {
    // Operator already on the modern emit surface — no legacy artifacts on
    // disk. Section renders the clean-positive so the opt-in invocation
    // confirms the audit ran (not skipped silently).
    $dir = doctorTempProject('BoostConfig::configure()->withAgents([Agent::COPILOT])');
    try {
        $result = runDoctorWithOption($dir, '--check-stale-paths');
        expect($result['exit'])->toBe(0)
            ->and($result['display'])->toContain('Stale paths')
            ->and($result['display'])->toContain('Clean');
    } finally {
        doctorCleanup($dir);
    }
});

it('0.10.1 --check-conventions: NO-schemas-published case does NOT triage as "all declarations malformed" (codex-review regression guard)', function (): void {
    // codex-review caught a 0.10.1-draft regression: SchemaDiscovery's
    // noise-collapse summary INFO populated the diagnostics list even when
    // no vendor shipped a malformed schema. DoctorCommand::reportConventions
    // pre-fix branched on `sources === [] && diagnostics !== []` as "all
    // malformed", false-positive-triaging a clean "no schemas published yet"
    // project. Filter-by-level fix: only warning/error diagnostics route
    // through the malformed branch.
    $vendorPath = sys_get_temp_dir() . '/no-schema-vendor-' . bin2hex(random_bytes(8));
    mkdir($vendorPath, 0o755, recursive: true);
    file_put_contents($vendorPath . '/composer.json', (string) json_encode([
        'name' => 'vendor/no-schema',
        'extra' => ['boost' => ['skills' => 'resources/boost/skills']],
    ]));

    $dir = doctorTempProject(
        "BoostConfig::configure()->withAgents([Agent::CLAUDE_CODE])->withAllowedVendors(['vendor/no-schema'])",
    );
    try {
        $command = new DoctorCommand(
            injectedPackages: new InstalledPackages([
                'vendor/no-schema' => new PackageInfo(
                    name: 'vendor/no-schema',
                    version: '1.0.0',
                    installPath: $vendorPath,
                ),
            ]),
        );
        $app = new ComposerApplication();
        $app->addCommand($command);
        $tester = new CommandTester($command);
        $exit = $tester->execute(['--working-dir' => $dir, '--check-conventions' => true]);

        $display = preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '';
        expect($exit)->toBe(0)
            ->and($display)->toContain('Project Conventions')
            ->and($display)->toContain('No conventions schemas declared')
            ->and($display)->not->toContain('all declarations malformed');
    } finally {
        doctorCleanup($dir);
        doctorCleanup($vendorPath);
    }
});

it('0.10.1 --check-stale-paths: scoped-not-applicable when Copilot is NOT in active agents (registry is Copilot-scoped)', function (): void {
    // Registry entries are Copilot-emitted; absence of Copilot in
    // withAgents() means sync has no cleanup intent for these paths.
    // The audit explicitly says "nothing to audit" rather than silently
    // returning clean — the latter would lie when a non-Copilot project
    // happens to have a `.github/skills/` from an unrelated source.
    $dir = doctorTempProject('BoostConfig::configure()->withAgents([Agent::CLAUDE_CODE])');
    mkdir($dir . '/.github/skills', 0o755, recursive: true);
    file_put_contents($dir . '/.github/copilot-instructions.md', 'unrelated content');
    try {
        $result = runDoctorWithOption($dir, '--check-stale-paths');
        $display = preg_replace('/\s+/', ' ', $result['display']) ?? '';
        expect($result['exit'])->toBe(0)
            ->and($display)->toContain('Stale paths')
            ->and($display)->toContain('Copilot not in active agents')
            ->and($display)->not->toContain('.github/copilot-instructions.md');
    } finally {
        doctorCleanup($dir);
    }
});

it('0.12.0 exclude-key surfacing: bare-name withExcludedSkills entry is flagged as a silent no-op', function (): void {
    // `withExcludedSkills(['pre-release'])` silently does nothing — the key
    // must be `vendor/package:pre-release`. Doctor surfaces the bare-name
    // mismatch so the operator can fix the key.
    $dir = doctorTempProject(
        "BoostConfig::configure()->withAgents([Agent::CLAUDE_CODE])->withExcludedSkills(['pre-release'])",
    );
    try {
        $result = runDoctor($dir);
        $display = preg_replace('/\s+/', ' ', $result['display']) ?? '';
        expect($result['exit'])->toBe(0)
            ->and($display)->toContain('Exclude keys')
            ->and($display)->toContain('withExcludedSkills(): `pre-release`')
            ->and($display)->toContain('bare name');
    } finally {
        doctorCleanup($dir);
    }
});

it('0.12.0 exclude-key surfacing: a well-formed `vendor/package:name` exclude is NOT flagged (no false positive — codex P2: remote/injected excludes are valid too)', function (): void {
    // We flag ONLY bare names. A `vendor/package:name` key is well-formed even
    // if the vendor isn't in withAllowedVendors() — it could be a remote-skill
    // (withRemoteSkills, owner/repo:name) or injected-vendor key. Flagging it
    // would false-positive on those supported configs + on every valid exclude
    // (a valid exclude removes its item from the resolved set).
    $dir = doctorTempProject(
        "BoostConfig::configure()->withAgents([Agent::CLAUDE_CODE])->withExcludedGuidelines(['acme/nope:ghost'])",
    );
    try {
        $result = runDoctor($dir);
        $display = preg_replace('/\s+/', ' ', $result['display']) ?? '';
        expect($result['exit'])->toBe(0)
            ->and($display)->toContain('Exclude keys')
            ->and($display)->toContain('well-formed')
            ->and($display)->not->toContain('withExcludedGuidelines(): `acme/nope:ghost`');
    } finally {
        doctorCleanup($dir);
    }
});

it('0.12.0 exclude-key surfacing: section omitted entirely when no excludes declared', function (): void {
    $dir = doctorTempProject('BoostConfig::configure()->withAgents([Agent::CLAUDE_CODE])');
    try {
        $result = runDoctor($dir);
        expect($result['exit'])->toBe(0)
            ->and($result['display'])->not->toContain('Exclude keys');
    } finally {
        doctorCleanup($dir);
    }
});

it('0.16.0 conventions-token leak: reports a surviving boost:conv fence in an emitted file', function (): void {
    $dir = doctorTempProject('BoostConfig::configure()->withAgents([Agent::CLAUDE_CODE])');
    try {
        // A surviving opt-in fence info-string in emitted output = definitive leak
        // (a 0.15+ engine would have stripped it on processing).
        file_put_contents($dir . '/CLAUDE.md', "# Project\n\n```yaml boost:conv\npatterns:\n  - main\n```\n");

        $result = runDoctor($dir);
        $display = preg_replace('/\s+/', ' ', $result['display']) ?? '';

        expect($result['exit'])->toBe(0) // doctor is advisory — never fails the build
            ->and($display)->toContain('Conventions tokens')
            ->and($display)->toContain('leaked conventions token')
            ->and($display)->toContain('CLAUDE.md');
    } finally {
        doctorCleanup($dir);
    }
});

it('0.16.0 conventions-token leak: clean project reports no leaks', function (): void {
    $dir = doctorTempProject('BoostConfig::configure()->withAgents([Agent::CLAUDE_CODE])');
    try {
        file_put_contents($dir . '/CLAUDE.md', "# Project\n\nNo tokens here.\n");

        $result = runDoctor($dir);
        $display = preg_replace('/\s+/', ' ', $result['display']) ?? '';

        expect($result['exit'])->toBe(0)
            ->and($display)->toContain('No leaked conventions tokens');
    } finally {
        doctorCleanup($dir);
    }
});

it('doctor: reports the both-configs ambiguity as a section and fails cleanly (#89)', function (): void {
    $dir = sys_get_temp_dir() . '/boost-doctor-ambig-' . bin2hex(random_bytes(8));
    mkdir($dir . '/.config', 0o755, recursive: true);
    mkdir($dir . '/vendor', 0o755, recursive: true);
    $body = '<?php return ' . BoostConfig::class . '::configure();';
    file_put_contents($dir . '/boost.php', $body);
    file_put_contents($dir . '/.config/boost.php', $body);

    try {
        $result = runDoctor($dir);
        expect($result['exit'])->toBe(1)
            ->and($result['display'])->toContain('Config location')
            ->and($result['display'])->toContain('Two boost configs found');
    } finally {
        doctorCleanup($dir);
    }
});

it('doctor: resolves and names a .config/boost.php config (#89)', function (): void {
    $dir = sys_get_temp_dir() . '/boost-doctor-cfgdir-' . bin2hex(random_bytes(8));
    mkdir($dir . '/.config', 0o755, recursive: true);
    mkdir($dir . '/vendor', 0o755, recursive: true);
    file_put_contents(
        $dir . '/.config/boost.php',
        "<?php\nuse SanderMuller\\BoostCore\\Config\\BoostConfig;\nuse SanderMuller\\BoostCore\\Enums\\Agent;\nreturn BoostConfig::configure()->withAgents([Agent::CLAUDE_CODE]);\n",
    );

    try {
        $result = runDoctor($dir);
        // Exit 0 means the config loaded + every section ran; the `Config:` line
        // (writeln, not wrapped) names the resolved path. Avoid asserting the
        // success()-block "parses cleanly" text — SymfonyStyle word-wraps it at
        // width 80, so a long temp path can split the substring across a newline.
        expect($result['exit'])->toBe(0)
            ->and($result['display'])->toContain('.config/boost.php');
    } finally {
        doctorCleanup($dir);
    }
});

it('doctor: --config loads an explicit config path, overriding auto-discovery (#89)', function (): void {
    $dir = sys_get_temp_dir() . '/boost-doctor-cfgflag-' . bin2hex(random_bytes(8));
    mkdir($dir . '/vendor', 0o755, recursive: true);
    mkdir($dir . '/custom', 0o755, recursive: true);
    // No root or .config/ boost.php — only a custom-named file the flag points at.
    file_put_contents(
        $dir . '/custom/my-boost.php',
        "<?php\nuse SanderMuller\\BoostCore\\Config\\BoostConfig;\nuse SanderMuller\\BoostCore\\Enums\\Agent;\nreturn BoostConfig::configure()->withAgents([Agent::CLAUDE_CODE]);\n",
    );

    $command = new DoctorCommand();
    $app = new ComposerApplication();
    $app->addCommand($command);

    $tester = new CommandTester($command);

    try {
        $exit = $tester->execute(['--working-dir' => $dir, '--config' => 'custom/my-boost.php']);
        $display = $tester->getDisplay();
        // Exit 0 proves the explicit config loaded + all sections ran; the
        // `Config:` line (writeln) names it. Don't assert the success()-block
        // "parses cleanly" — it word-wraps at width 80 and a long temp path
        // splits the substring across a newline (green locally, red on CI).
        expect($exit)->toBe(0)
            ->and($display)->toContain('custom/my-boost.php')
            // The drift section must use the SAME override, not fall back to
            // auto-discovery (which would fail — there is no root/.config config).
            ->and($display)->not->toContain('Could not check drift');
    } finally {
        doctorCleanup($dir);
    }
});

it('doctor: surfaces a guideline with no registered renderer as a skip warning (#85)', function (): void {
    $dir = doctorTempProject('BoostConfig::configure()->withAgents([Agent::CLAUDE_CODE])');
    try {
        mkdir($dir . '/.ai/guidelines', 0o755, recursive: true);
        // No BladeRenderer registered → this is silently dropped by the loader;
        // doctor must surface it (the silent-capability-loss health check).
        file_put_contents($dir . '/.ai/guidelines/styling.blade.php', "---\nname: styling\n---\nBlade.");

        $result = runDoctor($dir);

        // Assert the file path (a single token — wrap-safe in SymfonyStyle's
        // warning block, unlike a multi-word phrase).
        expect($result['exit'])->toBe(0)
            ->and($result['display'])->toContain('styling.blade.php');
    } finally {
        doctorCleanup($dir);
    }
});

it('doctor: stays quiet about source rendering when every guideline is .md (#85)', function (): void {
    $dir = doctorTempProject('BoostConfig::configure()->withAgents([Agent::CLAUDE_CODE])');
    try {
        mkdir($dir . '/.ai/guidelines', 0o755, recursive: true);
        file_put_contents($dir . '/.ai/guidelines/team.md', "---\nname: team\n---\nBody.");

        $result = runDoctor($dir);

        expect($result['exit'])->toBe(0)
            ->and($result['display'])->not->toContain('no renderer registered');
    } finally {
        doctorCleanup($dir);
    }
});

it('doctor: surfaces an unrenderable ALLOWLISTED-VENDOR source, matching what sync drops (#87 codex P2)', function (): void {
    $dir = doctorTempProject("BoostConfig::configure()->withAgents([Agent::CLAUDE_CODE])->withAllowedVendors(['acme/x'])");
    $vendor = sys_get_temp_dir() . '/boost-doctor-vendor-' . bin2hex(random_bytes(8));
    mkdir($vendor . '/resources/boost/skills/templated', 0o755, recursive: true);
    file_put_contents($vendor . '/composer.json', json_encode(['name' => 'acme/x'], JSON_THROW_ON_ERROR));
    file_put_contents($vendor . '/resources/boost/skills/templated/SKILL.blade.php', "---\nname: templated\n---\nBlade.");

    $packages = new InstalledPackages([
        'acme/x' => new PackageInfo('acme/x', '1.0.0', $vendor),
    ]);

    try {
        $command = new DoctorCommand(injectedPackages: $packages);
        $app = new ComposerApplication();
        $app->addCommand($command);
        $tester = new CommandTester($command);
        $exit = $tester->execute(['--working-dir' => $dir]);

        expect($exit)->toBe(0)
            ->and($tester->getDisplay())->toContain('SKILL.blade.php');
    } finally {
        doctorCleanup($dir);
        doctorCleanup($vendor);
    }
});

it('0.18.1 doctor: reports the runtime manifest at root .boost/ for the default layout', function (): void {
    $dir = doctorTempProject('BoostConfig::configure()->withAgents([Agent::CLAUDE_CODE])');
    try {
        $command = new DoctorCommand(injectedPackages: new InstalledPackages([]));
        $tester = new CommandTester($command);
        $tester->execute(['--working-dir' => $dir]);
        $display = preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '';
        expect($display)->toContain('Runtime manifest: .boost/manifest.json');
    } finally {
        doctorCleanup($dir);
    }
});

it('0.18.1 doctor: reports the runtime manifest at .config/boost/ for the .config/ layout', function (): void {
    $dir = sys_get_temp_dir() . '/boost-doctor-cfg-' . bin2hex(random_bytes(8));
    mkdir($dir . '/.config', 0o755, recursive: true);
    mkdir($dir . '/vendor', 0o755, recursive: true);
    file_put_contents(
        $dir . '/.config/boost.php',
        "<?php\nuse SanderMuller\\BoostCore\\Config\\BoostConfig;\nuse SanderMuller\\BoostCore\\Enums\\Agent;\nreturn BoostConfig::configure()->withAgents([Agent::CLAUDE_CODE]);\n",
    );
    try {
        $command = new DoctorCommand(injectedPackages: new InstalledPackages([]));
        $tester = new CommandTester($command);
        $tester->execute(['--working-dir' => $dir]);
        $display = preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '';
        expect($display)->toContain('Runtime manifest: .config/boost/manifest.json');
    } finally {
        doctorCleanup($dir);
    }
});

it('0.18.1 doctor: omits the runtime-manifest line when gitignore management is disabled (codex P3 — no manifest is used)', function (): void {
    $dir = doctorTempProject('BoostConfig::configure()->withAgents([Agent::CLAUDE_CODE])->withGitignoreManagement(false)');
    try {
        $command = new DoctorCommand(injectedPackages: new InstalledPackages([]));
        $tester = new CommandTester($command);
        $tester->execute(['--working-dir' => $dir]);
        $display = preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '';
        expect($display)->not->toContain('Runtime manifest:');
    } finally {
        doctorCleanup($dir);
    }
});

it('0.23.0 reportDrift is wrapper-aware: never recommends the destructive bare `boost sync` on a project-boost-laravel project (collectiq cuiqtty0)', function (): void {
    // A never-synced project with a host skill source drifts under a bare check-sync.
    // On a WRAPPER project that bare-CLI diff is expected (the wrapper composes the
    // emit surface), so doctor must NOT steer at `vendor/bin/boost sync` — that
    // command overwrites the wrapper-composed guidance with the degraded bare version.
    $dir = doctorTempProject('BoostConfig::configure()->withAgents([Agent::CLAUDE_CODE])');
    mkdir($dir . '/.ai/skills', 0o755, recursive: true);
    file_put_contents($dir . '/.ai/skills/foo.md', "---\nname: foo\n---\nbody\n");

    try {
        $packages = new InstalledPackages([
            'sandermuller/project-boost-laravel' => new PackageInfo(
                name: 'sandermuller/project-boost-laravel',
                version: '0.9.0',
                installPath: $dir,
            ),
        ]);
        $command = new DoctorCommand(injectedPackages: $packages);
        $tester = new CommandTester($command);
        $tester->execute(['--working-dir' => $dir]);
        $display = preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '';

        expect($display)->toContain('php artisan project-boost:sync --dry-run')
            ->and($display)->toContain('Do NOT run')
            // the destructive generic recommendation must NOT fire on a wrapper project
            ->and($display)->not->toContain('would change. Run `vendor/bin/boost sync`');
    } finally {
        doctorCleanup($dir);
    }
});

it('0.23.0 reportDrift keeps the plain `boost sync` recommendation on a NON-wrapper project', function (): void {
    $dir = doctorTempProject('BoostConfig::configure()->withAgents([Agent::CLAUDE_CODE])');
    mkdir($dir . '/.ai/skills', 0o755, recursive: true);
    file_put_contents($dir . '/.ai/skills/foo.md', "---\nname: foo\n---\nbody\n");

    try {
        $command = new DoctorCommand(injectedPackages: new InstalledPackages([]));
        $tester = new CommandTester($command);
        $tester->execute(['--working-dir' => $dir]);
        $display = preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '';

        expect($display)->toContain('would change. Run `vendor/bin/boost sync`')
            ->and($display)->not->toContain('project-boost:sync --dry-run');
    } finally {
        doctorCleanup($dir);
    }
});

it('0.23.0 reportUnrenderableSources is wrapper-aware: downgrades the no-renderer skip to a note on a wrapper project (mijntp)', function (): void {
    // A host .blade.php guideline has no BARE-CLI renderer, but the wrapper Blade-renders
    // it under `php artisan project-boost:sync`, so doctor must downgrade the bare-CLI
    // "no renderer" warning to an informational note (not silent data loss).
    $dir = doctorTempProject('BoostConfig::configure()->withAgents([Agent::CLAUDE_CODE])');
    mkdir($dir . '/.ai/guidelines', 0o755, recursive: true);
    file_put_contents($dir . '/.ai/guidelines/conventions.blade.php', "Conventions guideline\n");

    try {
        $packages = new InstalledPackages([
            'sandermuller/project-boost-laravel' => new PackageInfo(
                name: 'sandermuller/project-boost-laravel',
                version: '0.9.0',
                installPath: $dir,
            ),
        ]);
        $command = new DoctorCommand(injectedPackages: $packages);
        $tester = new CommandTester($command);
        $tester->execute(['--working-dir' => $dir]);
        $display = preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '';

        // The note framing only exists on the wrapper path (the non-wrapper path
        // emits a bare [WARNING] instead). Assert phrases that survive the [NOTE]
        // block's `! ` line-wrap prefixes.
        expect($display)->toContain('project-boost-laravel is installed')
            ->and($display)->toContain('the wrapper renders them under')
            ->and($display)->toContain('conventions.blade.php');
    } finally {
        doctorCleanup($dir);
    }
});

it('0.23.0 doctor reports dead + live agent-dir symlinks across all agents (project-boost)', function (): void {
    // Scans ALL known agent dirs (incl de-configured) — plant orphans under .cursor/
    // even though only CLAUDE_CODE is configured.
    $dir = doctorTempProject('BoostConfig::configure()->withAgents([Agent::CLAUDE_CODE])');
    mkdir($dir . '/.cursor/skills/oldvendor', 0o755, recursive: true);
    symlink($dir . '/gone-' . bin2hex(random_bytes(4)), $dir . '/.cursor/skills/oldvendor/deadlink'); // target absent → dead
    $liveTarget = $dir . '/livetarget';
    file_put_contents($liveTarget, "x\n");
    symlink($liveTarget, $dir . '/.cursor/skills/oldvendor/livelink'); // resolves → live

    try {
        $command = new DoctorCommand(injectedPackages: new InstalledPackages([]));
        $tester = new CommandTester($command);
        $tester->execute(['--working-dir' => $dir]);
        $display = preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '';

        expect($display)->toContain('Agent-dir symlinks')
            ->and($display)->toContain('deadlink')
            ->and($display)->toContain('livelink')
            ->and($display)->toContain('preserved by design');
    } finally {
        doctorCleanup($dir);
    }
});
