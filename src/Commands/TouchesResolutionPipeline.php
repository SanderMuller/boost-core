<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Commands;

use SanderMuller\BoostCore\Sync\WrapperEntryPointMap;

/**
 * Marks a command whose result depends on the skill/guideline RESOLUTION
 * pipeline — the discovery, render, tag-filter and injection chain a wrapper
 * package extends.
 *
 * Run bare in a project that installs a wrapper, such a command still
 * succeeds, but over boost-core's own sources only: whatever the wrapper
 * injects at runtime is missing, so the result is short rather than wrong-
 * looking. `boost tags` listing boost-core's skills while omitting a
 * wrapper's injected ones is the shape.
 *
 * Boost-core classifies its OWN commands here. A wrapper's coverage claim
 * (`extra.boost.entry-point`) is separate and INDEPENDENT — see
 * {@see WrapperEntryPointMap}. This marker only
 * decides who gets the short-result banner; it never decides a redirect.
 *
 * @internal
 */
interface TouchesResolutionPipeline {}
