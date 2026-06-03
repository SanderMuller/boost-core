<?php declare(strict_types=1);

use SanderMuller\BoostCore\Agents\AgentTarget;
use SanderMuller\BoostCore\Commands\BoostBaseCommand;
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
use SanderMuller\BoostCore\Sync\InstalledPackages;
use SanderMuller\BoostCore\Sync\PackageInfo;
use SanderMuller\BoostCore\Sync\SyncContext;

/**
 * 1.0 boundary guard (task #107/#109): every class in the engine namespaces must
 * be `@internal` so the 1.0 semver promise covers only the authoring API + CLI +
 * hooks. A new engine class added later must either carry `@internal` or be added
 * to one of the explicit allowlists below — a deliberate, reviewed act.
 *
 * Allowlist:
 *  - ENGINE_PUBLIC_API: consumer-facing classes that live in the engine
 *    namespaces but are part of the 1.0 public surface — they carry `@api`,
 *    never `@internal` (locked in task #108).
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

const ENGINE_PUBLIC_API = [
    RemoteSkillSource::class,
    RemoteSkillRef::class,
    AgentTarget::class,
    // Family-CLI extension point (0.22.0). @api is NARROW: only the protected
    // addWorkingDirOption() + resolveProjectRoot() helpers are frozen; the
    // config-option/loading helpers carry method-level @internal. The class
    // docblock is @api (no class-level @internal tag), so it belongs here.
    BoostBaseCommand::class,
    PassthroughRenderer::class,
    InvalidSkillRendererException::class,
    SkillRenderException::class,
    SyncContext::class,
    EmittedFile::class,
    RenderContext::class,
    InstalledPackages::class,
    PackageInfo::class,
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

/**
 * True only when `@internal` appears as a docblock TAG (after `* ` or `/**`),
 * not when an `@api` docblock merely mentions the word in prose.
 */
function hasInternalTag(string $doc): bool
{
    return preg_match('#(?:/\*\*|\*)\s+@internal\b#', $doc) === 1;
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
    $allowed = ENGINE_PUBLIC_API;

    $missing = [];
    foreach (boostSrcClasses() as $fqcn) {
        if (! isEngineClass($fqcn)) {
            continue;
        }

        if (in_array($fqcn, $allowed, true)) {
            continue;
        }

        $doc = (new ReflectionClass($fqcn))->getDocComment();
        if ($doc === false || ! hasInternalTag($doc)) {
            $missing[] = $fqcn;
        }
    }

    expect($missing)->toBe([], sprintf(
        'These engine classes are not @internal. Add `@internal` to the class docblock, '
        . "or (if genuinely consumer-facing) add them to ENGINE_PUBLIC_API:\n- %s",
        implode("\n- ", $missing),
    ));
});

it('marks the in-engine public-API carve-outs @api (and never @internal)', function (): void {
    // These live in engine namespaces but are part of the locked 1.0 surface
    // (task #108). Guard both directions: they must carry @api, and must never
    // pick up an @internal that would wrongly fence off the public surface.
    foreach (ENGINE_PUBLIC_API as $fqcn) {
        $doc = (string) (new ReflectionClass($fqcn))->getDocComment();
        expect($doc)->toContain('@api')
            ->and(hasInternalTag($doc))
            ->toBeFalse("{$fqcn} must not be @internal");
    }
});

it('freezes BoostBaseCommand NARROW: two @api helpers, the rest method-level @internal (0.22.0)', function (): void {
    // The family-CLI extension point promoted in 0.22.0. The @api guarantee is
    // exactly addWorkingDirOption() + resolveProjectRoot(); the config-option /
    // config-loading helpers stay @internal. Pin both halves so the narrow
    // promise can't silently widen (a future method must be a reviewed choice).
    $rc = new ReflectionClass(BoostBaseCommand::class);

    $frozen = ['addWorkingDirOption', 'resolveProjectRoot'];
    $internalHelpers = ['addConfigOption', 'configFileOption', 'loadConfig', 'isInteractiveOrExplain'];

    foreach ($frozen as $name) {
        $doc = (string) $rc->getMethod($name)->getDocComment();
        expect(hasInternalTag($doc))->toBeFalse("{$name} is the frozen @api surface; must not be @internal");
    }

    foreach ($internalHelpers as $name) {
        $doc = (string) $rc->getMethod($name)->getDocComment();
        expect(hasInternalTag($doc))->toBeTrue("{$name} is NOT frozen; must carry method-level @internal");
    }

    // Signature lock on the two frozen helpers.
    expect((string) $rc->getMethod('resolveProjectRoot')->getReturnType())->toBe('string')
        ->and($rc->getMethod('addWorkingDirOption')->getNumberOfParameters())->toBe(0)
        ->and($rc->getMethod('resolveProjectRoot')->getNumberOfRequiredParameters())->toBe(1);
});

it('never exposes an @internal type in an @api method signature or public property', function (): void {
    // A frozen @api method/property whose type is @internal is a footgun: a
    // consumer can't use it without touching the unstable engine. Lock the
    // invariant so the public surface stays self-contained for 1.x.
    $api = [];
    $internal = [];
    foreach (boostSrcClasses() as $fqcn) {
        $doc = (string) (new ReflectionClass($fqcn))->getDocComment();
        if (str_contains($doc, '@api')) {
            $api[] = $fqcn;
        }

        if (hasInternalTag($doc)) {
            $internal[$fqcn] = true;
        }
    }

    $internalNamesIn = static function (?ReflectionType $type) use ($internal): array {
        if (! $type instanceof ReflectionType) {
            return [];
        }

        $parts = $type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType
            ? $type->getTypes()
            : [$type];
        $hits = [];
        foreach ($parts as $part) {
            if ($part instanceof ReflectionNamedType && ! $part->isBuiltin() && isset($internal[ltrim($part->getName(), '\\')])) {
                $hits[] = ltrim($part->getName(), '\\');
            }
        }

        return $hits;
    };

    $leaks = [];
    foreach ($api as $fqcn) {
        $rc = new ReflectionClass($fqcn);
        foreach ($rc->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $fqcn) {
                continue;
            }

            if (str_contains((string) $method->getDocComment(), '@internal')) {
                continue;
            }

            foreach ($method->getParameters() as $param) {
                foreach ($internalNamesIn($param->getType()) as $hit) {
                    $leaks[] = "{$fqcn}::{$method->getName()}(\${$param->getName()}) → {$hit}";
                }
            }

            foreach ($internalNamesIn($method->getReturnType()) as $hit) {
                $leaks[] = "{$fqcn}::{$method->getName()}() return → {$hit}";
            }
        }

        foreach ($rc->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
            if ($prop->getDeclaringClass()->getName() !== $fqcn) {
                continue;
            }

            foreach ($internalNamesIn($prop->getType()) as $hit) {
                $leaks[] = "{$fqcn}::\${$prop->getName()} → {$hit}";
            }
        }
    }

    expect($leaks)->toBe([], sprintf(
        'These @api signatures expose an @internal type — either @api the type, or '
        . "mark the method/ctor @internal (if consumers can't reach it):\n- %s",
        implode("\n- ", $leaks),
    ));
});
