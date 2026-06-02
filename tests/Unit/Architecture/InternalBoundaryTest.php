<?php declare(strict_types=1);

use SanderMuller\BoostCore\Agents\AgentTarget;
use SanderMuller\BoostCore\Config\BoostConfigLoader;
use SanderMuller\BoostCore\Config\BoostConfigPath;
use SanderMuller\BoostCore\Config\BoostConfigPrinter;
use SanderMuller\BoostCore\Config\BoostConfigWriter;
use SanderMuller\BoostCore\Env;
use SanderMuller\BoostCore\Skills\Remote\RemoteSkillRef;
use SanderMuller\BoostCore\Skills\Remote\RemoteSkillSource;
use SanderMuller\BoostCore\Skills\Rendering\InvalidSkillRendererException;
use SanderMuller\BoostCore\Skills\Rendering\PassthroughRenderer;
use SanderMuller\BoostCore\Skills\Rendering\RenderContext;
use SanderMuller\BoostCore\Skills\Rendering\SkillRenderException;
use SanderMuller\BoostCore\Sync\EmittedFile;
use SanderMuller\BoostCore\Sync\SyncContext;

/**
 * 1.0 boundary guard (task #107/#109): every class in the engine namespaces must
 * be `@internal` so the 1.0 semver promise covers only the authoring API + CLI +
 * hooks. A new engine class added later must either carry `@internal` or be added
 * to one of the explicit allowlists below — a deliberate, reviewed act.
 *
 * Allowlists:
 *  - PUBLIC_OR_DEFERRED: consumer-reachable types intentionally NOT `@internal`
 *    (their `@api` status is decided in task #108).
 *  - EXPERIMENTAL_DTOS: contract DTOs that ride with the `@experimental`
 *    FileEmitter/SkillRenderer interfaces — they carry `@experimental`, not
 *    `@internal`, and are asserted to keep it.
 */
const ENGINE_NAMESPACE_PREFIXES = [
    'SanderMuller\\BoostCore\\Sync\\',
    'SanderMuller\\BoostCore\\Discovery\\',
    'SanderMuller\\BoostCore\\Conventions\\',
    'SanderMuller\\BoostCore\\Agents\\',
    'SanderMuller\\BoostCore\\Commands\\',
    'SanderMuller\\BoostCore\\Skills\\',
];

// Engine classes that live OUTSIDE the prefix dirs but are still internal.
const ENGINE_EXTRA_INTERNAL = [
    Env::class,
    BoostConfigLoader::class,
    BoostConfigWriter::class,
    BoostConfigPrinter::class,
    BoostConfigPath::class,
];

const PUBLIC_OR_DEFERRED = [
    RemoteSkillSource::class,
    RemoteSkillRef::class,
    AgentTarget::class,
    PassthroughRenderer::class,
    InvalidSkillRendererException::class,
    SkillRenderException::class,
];

const EXPERIMENTAL_DTOS = [
    SyncContext::class,
    EmittedFile::class,
    RenderContext::class,
];

/**
 * @return list<class-string>
 */
function boostSrcClasses(): array
{
    $srcDir = dirname(__DIR__, 3) . '/src';
    $classes = [];
    /** @var iterable<SplFileInfo> $files */
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcDir, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $relative = substr($file->getPathname(), strlen($srcDir) + 1, -4);
        $fqcn = 'SanderMuller\\BoostCore\\' . str_replace('/', '\\', $relative);

        if (class_exists($fqcn) || interface_exists($fqcn) || enum_exists($fqcn) || trait_exists($fqcn)) {
            $classes[] = $fqcn;
        }
    }

    sort($classes);

    return $classes;
}

function isEngineClass(string $fqcn): bool
{
    if (in_array($fqcn, ENGINE_EXTRA_INTERNAL, true)) {
        return true;
    }

    foreach (ENGINE_NAMESPACE_PREFIXES as $prefix) {
        if (str_starts_with($fqcn, $prefix)) {
            return true;
        }
    }

    return false;
}

it('marks every engine class @internal (or explicitly allowlists it)', function (): void {
    $allowed = array_merge(PUBLIC_OR_DEFERRED, EXPERIMENTAL_DTOS);

    $missing = [];
    foreach (boostSrcClasses() as $fqcn) {
        if (! isEngineClass($fqcn)) {
            continue;
        }

        if (in_array($fqcn, $allowed, true)) {
            continue;
        }

        $doc = (new ReflectionClass($fqcn))->getDocComment();
        if ($doc === false || ! str_contains($doc, '@internal')) {
            $missing[] = $fqcn;
        }
    }

    expect($missing)->toBe([], sprintf(
        'These engine classes are not @internal. Add `@internal` to the class docblock, '
        . "or (if genuinely consumer-facing) add them to PUBLIC_OR_DEFERRED/EXPERIMENTAL_DTOS:\n- %s",
        implode("\n- ", $missing),
    ));
});

it('keeps @experimental on the contract DTOs that ride with the plugin interfaces', function (): void {
    foreach (EXPERIMENTAL_DTOS as $fqcn) {
        $doc = (new ReflectionClass($fqcn))->getDocComment();
        expect($doc)->toBeString();
        // `toContain` is variadic — a 2nd "message" arg is treated as another needle,
        // so assert plainly. The loop var names the offending class on failure.
        expect((string) $doc)->toContain('@experimental');
    }
});

it('does not let the public-or-deferred carve-outs silently become @internal', function (): void {
    // These are consumer-reachable; their status is decided in #108. Guard against an
    // accidental @internal that would wrongly fence off the authoring surface.
    foreach (PUBLIC_OR_DEFERRED as $fqcn) {
        $doc = (new ReflectionClass($fqcn))->getDocComment();
        $hasInternal = $doc !== false && str_contains($doc, '@internal');
        expect($hasInternal)->toBeFalse("{$fqcn} is consumer-reachable and must not be @internal (decide @api in #108).");
    }
});
