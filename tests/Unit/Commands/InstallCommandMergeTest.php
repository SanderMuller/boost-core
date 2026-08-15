<?php declare(strict_types=1);

use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;
use SanderMuller\BoostCore\Commands\InstallCommand;
use SanderMuller\BoostCore\Config\BoostConfigPath;
use SanderMuller\BoostCore\Sync\InstalledPackages;
use SanderMuller\BoostCore\Sync\PackageInfo;
use Symfony\Component\Console\Tester\CommandTester;

it('1.0 boost install: explains the skipped vendor + tag pickers and notes laravel/boost coexistence', function (): void {
    $dir = sys_get_temp_dir() . '/boost-install-notes-' . bin2hex(random_bytes(8));
    mkdir($dir, 0o755, recursive: true);
    file_put_contents(
        $dir . '/boost.php',
        "<?php\n\nuse SanderMuller\\BoostCore\\Config\\BoostConfig;\nuse SanderMuller\\BoostCore\\Enums\\Agent;\n\nreturn BoostConfig::configure()->withAgents([Agent::CLAUDE_CODE]);\n",
    );

    // The only interactive prompt is the agent picker (CLAUDE_CODE pre-checked) — ENTER
    // accepts it. The vendor + tag pickers skip (no publisher / no tags) before any
    // prompt; laravel/boost is injected but is not an allowlist publisher.
    Prompt::fake([Key::ENTER]);

    try {
        $packages = new InstalledPackages([
            'laravel/boost' => new PackageInfo(name: 'laravel/boost', version: '2.4.0', installPath: $dir),
        ]);
        $tester = new CommandTester(new InstallCommand(injectedPackages: $packages));
        $tester->execute(['--working-dir' => $dir], ['interactive' => true]);

        // Strip Symfony NOTE `!` prefixes + collapse whitespace so wrapped phrases match.
        $clean = (string) preg_replace('/\s+/', ' ', str_replace('!', ' ', $tester->getDisplay()));

        expect($clean)->toContain('vendor allowlist picker was skipped')
            ->and($clean)->toContain('tag picker was skipped')
            // laravel/boost WITHOUT the wrapper (codex P2): the note must steer to
            // INSTALLING the wrapper, NOT to a `project-boost:sync` command they lack.
            ->and($clean)->toContain('no coexistence sync path')
            ->and($clean)->toContain('Install sandermuller/project-boost-laravel');
    } finally {
        @unlink($dir . '/boost.php');
        @rmdir($dir);
    }
});

it('merges picker output with declared-but-not-discovered tags, picker order first, dedup', function (): void {
    $merged = InstallCommand::mergePickedWithPreserved(
        picked: ['php', 'laravel'],
        preserved: ['org-internal', 'phpstan-strict'],
    );

    expect($merged)->toBe(['php', 'laravel', 'org-internal', 'phpstan-strict']);
});

it('mergePickedWithPreserved: deduplicates overlap between the two sets', function (): void {
    // Defensive: if a tag somehow appears in BOTH the discovered-and-picked
    // set AND the preserved set (would mean array_diff failed in caller),
    // the merge should still produce a unique list.
    $merged = InstallCommand::mergePickedWithPreserved(
        picked: ['php', 'shared'],
        preserved: ['shared', 'org-internal'],
    );

    expect($merged)->toBe(['php', 'shared', 'org-internal']);
});

it('mergePickedWithPreserved: empty picker keeps preserved set', function (): void {
    // Regression guard: the operator un-checked every visible tag but
    // had declared org-internal — that tag must survive.
    $merged = InstallCommand::mergePickedWithPreserved(
        picked: [],
        preserved: ['org-internal'],
    );

    expect($merged)->toBe(['org-internal']);
});

it('mergePickedWithPreserved: empty preserved returns picker output unchanged', function (): void {
    $merged = InstallCommand::mergePickedWithPreserved(
        picked: ['php', 'jira'],
        preserved: [],
    );

    expect($merged)->toBe(['php', 'jira']);
});

it('scaffoldTarget: --config-dir picks .config/boost.php for a fresh project (#89)', function (): void {
    $dir = sys_get_temp_dir() . '/boost-install-tgt-' . bin2hex(random_bytes(8));
    mkdir($dir, 0o755, recursive: true);
    try {
        $resolved = BoostConfigPath::resolve($dir);
        expect(InstallCommand::scaffoldTarget($resolved, true, $dir))->toBe($dir . '/.config/boost.php')
            ->and(InstallCommand::scaffoldTarget($resolved, false, $dir))->toBe($dir . '/boost.php');
    } finally {
        @rmdir($dir);
    }
});

it('scaffoldTarget: an existing config wins — --config-dir never creates a second (#89)', function (): void {
    $dir = sys_get_temp_dir() . '/boost-install-existing-' . bin2hex(random_bytes(8));
    mkdir($dir . '/.config', 0o755, recursive: true);
    file_put_contents($dir . '/.config/boost.php', '<?php return null;');
    try {
        $resolved = BoostConfigPath::resolve($dir);
        // Even with --config-dir false, the existing .config/ config is edited in place.
        expect(InstallCommand::scaffoldTarget($resolved, false, $dir))->toBe($dir . '/.config/boost.php');
    } finally {
        @unlink($dir . '/.config/boost.php');
        @rmdir($dir . '/.config');
        @rmdir($dir);
    }
});

it('adopts laravel/boost agents as picker defaults when scaffolding a config', function (): void {
    // Adopting boost-core on a project that ran `boost:install` first: the agent list
    // already exists in laravel/boost's boost.json, so the picker pre-selects it
    // instead of asking the operator to remember what they chose.
    $dir = sys_get_temp_dir() . '/boost-install-adopt-' . bin2hex(random_bytes(8));
    mkdir($dir, 0o755, recursive: true);
    file_put_contents($dir . '/boost.json', json_encode([
        'agents' => ['claude_code', 'copilot', 'zed'],
    ], JSON_THROW_ON_ERROR));

    Prompt::fake([Key::ENTER]);

    try {
        $packages = new InstalledPackages([
            'laravel/boost' => new PackageInfo(name: 'laravel/boost', version: '2.4.0', installPath: $dir),
        ]);
        $tester = new CommandTester(new InstallCommand(injectedPackages: $packages));
        $tester->execute(['--working-dir' => $dir], ['interactive' => true]);

        $clean = (string) preg_replace('/\s+/', ' ', str_replace('!', ' ', $tester->getDisplay()));

        expect($clean)->toContain('laravel/boost is already set up for')
            ->and($clean)->toContain('claude-code, copilot')
            // `zed` has no boost-core agent — named as not carried over, not dropped silently.
            ->and($clean)->toContain('zed');

        // ENTER accepts the pre-selected defaults, so they land in the scaffolded config.
        $written = (string) file_get_contents($dir . '/boost.php');
        expect($written)->toContain('Agent::CLAUDE_CODE')
            ->and($written)->toContain('Agent::COPILOT');
    } finally {
        @unlink($dir . '/boost.php');
        @unlink($dir . '/boost.json');
        @rmdir($dir);
    }
});

it('does NOT adopt into an existing config that declares withAgents([])', function (): void {
    // An empty list in an EXISTING config is a decision, not a blank slate: the
    // operator turned every agent off. Repopulating it from laravel/boost's state
    // would silently undo that.
    $dir = sys_get_temp_dir() . '/boost-install-emptied-' . bin2hex(random_bytes(8));
    mkdir($dir, 0o755, recursive: true);
    file_put_contents(
        $dir . '/boost.php',
        "<?php\n\nuse SanderMuller\\BoostCore\\Config\\BoostConfig;\n\nreturn BoostConfig::configure()->withAgents([]);\n",
    );
    file_put_contents($dir . '/boost.json', json_encode([
        'agents' => ['claude_code', 'copilot'],
    ], JSON_THROW_ON_ERROR));

    Prompt::fake([Key::ENTER]);

    try {
        $packages = new InstalledPackages([
            'laravel/boost' => new PackageInfo(name: 'laravel/boost', version: '2.4.0', installPath: $dir),
        ]);
        $tester = new CommandTester(new InstallCommand(injectedPackages: $packages));
        $tester->execute(['--working-dir' => $dir], ['interactive' => true]);

        $clean = (string) preg_replace('/\s+/', ' ', str_replace('!', ' ', $tester->getDisplay()));
        expect($clean)->not->toContain('already set up for');

        $written = (string) file_get_contents($dir . '/boost.php');
        expect($written)->not->toContain('Agent::CLAUDE_CODE')
            ->and($written)->not->toContain('Agent::COPILOT');
    } finally {
        @unlink($dir . '/boost.php');
        @unlink($dir . '/boost.json');
        @rmdir($dir);
    }
});

it('does NOT adopt into an existing config that already declares agents', function (): void {
    $dir = sys_get_temp_dir() . '/boost-install-noadopt-' . bin2hex(random_bytes(8));
    mkdir($dir, 0o755, recursive: true);
    file_put_contents(
        $dir . '/boost.php',
        "<?php\n\nuse SanderMuller\\BoostCore\\Config\\BoostConfig;\nuse SanderMuller\\BoostCore\\Enums\\Agent;\n\nreturn BoostConfig::configure()->withAgents([Agent::CLAUDE_CODE]);\n",
    );
    file_put_contents($dir . '/boost.json', json_encode([
        'agents' => ['claude_code', 'copilot'],
    ], JSON_THROW_ON_ERROR));

    Prompt::fake([Key::ENTER]);

    try {
        $packages = new InstalledPackages([
            'laravel/boost' => new PackageInfo(name: 'laravel/boost', version: '2.4.0', installPath: $dir),
        ]);
        $tester = new CommandTester(new InstallCommand(injectedPackages: $packages));
        $tester->execute(['--working-dir' => $dir], ['interactive' => true]);

        $clean = (string) preg_replace('/\s+/', ' ', str_replace('!', ' ', $tester->getDisplay()));
        expect($clean)->not->toContain('already set up for')
            ->and((string) file_get_contents($dir . '/boost.php'))->not->toContain('Agent::COPILOT');
    } finally {
        @unlink($dir . '/boost.php');
        @unlink($dir . '/boost.json');
        @rmdir($dir);
    }
});

it('does not adopt agents from a boost.json when laravel/boost is not installed', function (): void {
    // A leftover boost.json from a since-removed laravel/boost must not seed the picker.
    $dir = sys_get_temp_dir() . '/boost-install-stale-' . bin2hex(random_bytes(8));
    mkdir($dir, 0o755, recursive: true);
    file_put_contents($dir . '/boost.json', json_encode(['agents' => ['claude_code']], JSON_THROW_ON_ERROR));

    Prompt::fake([Key::ENTER]);

    try {
        $tester = new CommandTester(new InstallCommand(injectedPackages: new InstalledPackages([])));
        $tester->execute(['--working-dir' => $dir], ['interactive' => true]);

        $clean = (string) preg_replace('/\s+/', ' ', str_replace('!', ' ', $tester->getDisplay()));
        expect($clean)->not->toContain('already set up for');
        // The starter's example comment mentions agent cases, so assert on the call itself.
        expect((string) file_get_contents($dir . '/boost.php'))->toContain('withAgents([])');
    } finally {
        @unlink($dir . '/boost.php');
        @unlink($dir . '/boost.json');
        @rmdir($dir);
    }
});

it('still adopts on an interactive retry after a non-interactive run left a starter behind', function (): void {
    // Scaffolding happens before the TTY check, so a non-interactive first attempt
    // leaves an untouched starter on disk. That must not count as "the operator chose
    // no agents" — the retry should still pre-select laravel/boost's list.
    $dir = sys_get_temp_dir() . '/boost-install-retry-' . bin2hex(random_bytes(8));
    mkdir($dir, 0o755, recursive: true);
    file_put_contents($dir . '/boost.json', json_encode(['agents' => ['claude_code']], JSON_THROW_ON_ERROR));

    try {
        $packages = new InstalledPackages([
            'laravel/boost' => new PackageInfo(name: 'laravel/boost', version: '2.4.0', installPath: $dir),
        ]);

        // First attempt: no TTY — scaffolds, then bails before the picker.
        $first = new CommandTester(new InstallCommand(injectedPackages: $packages));
        $first->execute(['--working-dir' => $dir], ['interactive' => false]);
        expect(file_exists($dir . '/boost.php'))->toBeTrue();

        Prompt::fake([Key::ENTER]);

        $second = new CommandTester(new InstallCommand(injectedPackages: $packages));
        $second->execute(['--working-dir' => $dir], ['interactive' => true]);

        $clean = (string) preg_replace('/\s+/', ' ', str_replace('!', ' ', $second->getDisplay()));
        expect($clean)->toContain('already set up for')
            ->and((string) file_get_contents($dir . '/boost.php'))
            ->toContain('Agent::CLAUDE_CODE');
    } finally {
        @unlink($dir . '/boost.php');
        @unlink($dir . '/boost.json');
        @rmdir($dir);
    }
});

it('explains an all-unsupported laravel/boost agent list instead of staying silent', function (): void {
    $dir = sys_get_temp_dir() . '/boost-install-unsupported-' . bin2hex(random_bytes(8));
    mkdir($dir, 0o755, recursive: true);
    file_put_contents($dir . '/boost.json', json_encode(['agents' => ['zed', 'pi']], JSON_THROW_ON_ERROR));

    Prompt::fake([Key::ENTER]);

    try {
        $packages = new InstalledPackages([
            'laravel/boost' => new PackageInfo(name: 'laravel/boost', version: '2.4.0', installPath: $dir),
        ]);
        $tester = new CommandTester(new InstallCommand(injectedPackages: $packages));
        $tester->execute(['--working-dir' => $dir], ['interactive' => true]);

        $clean = (string) preg_replace('/\s+/', ' ', str_replace('!', ' ', $tester->getDisplay()));

        expect($clean)->toContain('boost-core has no agent for these')
            ->and($clean)->toContain('zed, pi');
    } finally {
        @unlink($dir . '/boost.php');
        @unlink($dir . '/boost.json');
        @rmdir($dir);
    }
});
