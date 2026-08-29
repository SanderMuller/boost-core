<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Sync;

/**
 * The resolved entry-point claims of every installed wrapper package — which
 * bare boost-core commands should not be run directly in this project, and
 * what to run instead.
 *
 * A claim is an INDEPENDENT assertion by the wrapper, not a refinement of
 * boost-core's own view of a command. A wrapper knows reasons a bare command
 * is wrong that live in the host framework's runtime and are invisible here.
 * The worked example: `project-boost-laravel` intercepts `laravel/boost`'s
 * installer through Laravel's `CommandStarting` event to force `--mcp`, which
 * keeps laravel/boost's writers away from the guidance files its own sync
 * owns. That listener cannot fire for a bare-binary process, so bare `boost
 * install` there is a parallel-writer collision — while boost-core's own
 * classification would rate `install` as touching nothing.
 *
 * So an implementor must read "covered" as "do not run this bare here", NOT
 * as "we ship a like-for-like replacement".
 *
 * @internal
 */
final readonly class WrapperEntryPointMap
{
    /**
     * @param  array<string, array{package: string, invocation: string}>  $claims  bare command name => claim
     * @param  array<string, list<string>>  $reservedClaims  package => reserved command names it tried to claim
     * @param  array<string, list<string>>  $conflictingClaims  command => packages whose claim lost to an earlier one
     * @param  array<string, list<string>>  $selfClaims  root package => commands it claimed for itself
     */
    public function __construct(
        private array $claims = [],
        private array $reservedClaims = [],
        private array $conflictingClaims = [],
        private array $selfClaims = [],
    ) {}

    /**
     * True when at least one installed package declares an entry point. Drives
     * the short-result banner for pipeline-touching commands the wrapper does
     * NOT cover — those still run, but their output is incomplete.
     */
    public function hasWrapper(): bool
    {
        // ACCEPTED claims only. A package whose sole declaration was a reserved
        // command has had it dropped, so nothing is known about whether it
        // extends the resolution pipeline — and the short-result banner is a
        // statement that it does. Asserting that on the strength of a claim
        // boost-core refused to honour would be a confident answer with
        // nothing behind it.
        return $this->claims !== [];
    }

    public function covers(string $command): bool
    {
        return isset($this->claims[$command]);
    }

    public function invocationFor(string $command): ?string
    {
        return $this->claims[$command]['invocation'] ?? null;
    }

    public function packageFor(string $command): ?string
    {
        return $this->claims[$command]['package'] ?? null;
    }

    /**
     * Every wrapper package that declared an entry point, in discovery order.
     *
     * @return list<string>
     */
    public function packages(): array
    {
        $packages = [];
        foreach ($this->claims as $claim) {
            if (! in_array($claim['package'], $packages, strict: true)) {
                $packages[] = $claim['package'];
            }
        }

        foreach (array_keys($this->reservedClaims) as $package) {
            if (! in_array($package, $packages, strict: true)) {
                $packages[] = $package;
            }
        }

        return $packages;
    }

    /**
     * Claims boost-core refused to honour because the command is reserved.
     * Reported by `boost doctor` ONLY: it is a wrapper author's mistake, and
     * printing it on every run would turn one package's bug into permanent
     * noise for operators who cannot fix it.
     *
     * @return array<string, list<string>>  package => reserved command names
     */
    public function reservedClaims(): array
    {
        return $this->reservedClaims;
    }

    /**
     * Claims that lost to an earlier package's claim on the same command.
     *
     * First-declaration-wins is the only defensible resolution — preferring a
     * later package would be just as arbitrary — but resolving it SILENTLY
     * left the advisory naming one wrapper's invocation with nothing to
     * indicate another had asked for the same command. Reported by
     * `boost doctor`, like reserved claims: it is a project misconfiguration
     * the operator can act on, not per-run noise.
     *
     * @return array<string, list<string>>  command => the packages that lost
     */
    public function conflictingClaims(): array
    {
        return $this->conflictingClaims;
    }

    /**
     * Commands the ROOT package claimed for its own repository. Ignored — the
     * map describes projects that install a package, not the package itself —
     * and reported by `boost doctor` so the declaration is never silently
     * dropped.
     *
     * @return array<string, list<string>>  root package => the commands it claimed
     */
    public function selfClaims(): array
    {
        return $this->selfClaims;
    }
}
