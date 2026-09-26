<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Sync;

/**
 * What a real `--scope=user --all` run would already have changed on disk by
 * the time it reaches a given package — carried package to package in
 * `--check` mode, where nothing is written or deleted.
 *
 * Without it, `--check` reads the untouched disk: it misses a clash with a
 * package synced earlier in the same run, and reports a clash with a removed
 * package that the real run reaps first.
 *
 * @internal
 */
final readonly class UserScopeCheckOverlay
{
    /**
     * @param  array<string, true>  $absent  paths an earlier step would delete
     * @param  list<string>  $ignoredSlugs  manifests an earlier step would delete
     * @param  array<string, string>  $claimed  path => package an earlier step would write it for
     */
    public function __construct(
        public array $absent = [],
        public array $ignoredSlugs = [],
        public array $claimed = [],
    ) {}

    /**
     * Fold one package's check result in: its planned writes become claimed,
     * its would-deletes become absent. A refused package claims nothing — the
     * real run writes nothing for it.
     */
    public function after(UserScopeResult $result): self
    {
        if ($result->hasErrors()) {
            return $this;
        }

        $absent = $this->absent;
        $claimed = $this->claimed;
        foreach ($result->writes as $write) {
            if ($write->action === WriteAction::WOULD_DELETE || $write->action === WriteAction::DELETED) {
                $absent[$write->relativePath] = true;
                unset($claimed[$write->relativePath]);
            } elseif ($write->action !== WriteAction::SKIPPED_SYMLINK) {
                $claimed[$write->relativePath] = $result->packageName;
                unset($absent[$write->relativePath]);
            }
        }

        return new self($absent, $this->ignoredSlugs, $claimed);
    }
}
