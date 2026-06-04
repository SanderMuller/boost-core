<?php declare(strict_types=1);

use SanderMuller\BoostCore\Agents\AgentTarget;
use SanderMuller\BoostCore\Commands\BoostBaseCommand;
use SanderMuller\BoostCore\Config\AmbiguousBoostConfigException;
use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Config\BoostConfigLoader;
use SanderMuller\BoostCore\Config\BoostConfigNotFoundException;
use SanderMuller\BoostCore\Config\BoostConfigPath;
use SanderMuller\BoostCore\Config\BoostConfigPrinter;
use SanderMuller\BoostCore\Config\BoostConfigWriter;
use SanderMuller\BoostCore\Config\InvalidBoostConfigException;
use SanderMuller\BoostCore\Conventions\Diagnostic;
use SanderMuller\BoostCore\Env;
use SanderMuller\BoostCore\Skills\BoostTags;
use SanderMuller\BoostCore\Skills\FrontmatterParser;
use SanderMuller\BoostCore\Skills\Guideline;
use SanderMuller\BoostCore\Skills\ParsedDocument;
use SanderMuller\BoostCore\Skills\Remote\RemoteSkillRef;
use SanderMuller\BoostCore\Skills\Remote\RemoteSkillSource;
use SanderMuller\BoostCore\Skills\Rendering\InvalidSkillRendererException;
use SanderMuller\BoostCore\Skills\Rendering\PassthroughRenderer;
use SanderMuller\BoostCore\Skills\Rendering\RenderContext;
use SanderMuller\BoostCore\Skills\Rendering\SkillRenderException;
use SanderMuller\BoostCore\Skills\Skill;
use SanderMuller\BoostCore\Sync\BoostSync;
use SanderMuller\BoostCore\Sync\EmittedFile;
use SanderMuller\BoostCore\Sync\EmitterAction;
use SanderMuller\BoostCore\Sync\EmitterResult;
use SanderMuller\BoostCore\Sync\InstalledPackages;
use SanderMuller\BoostCore\Sync\PackageInfo;
use SanderMuller\BoostCore\Sync\SyncContext;
use SanderMuller\BoostCore\Sync\SyncResult;
use SanderMuller\BoostCore\Sync\WriteAction;
use SanderMuller\BoostCore\Sync\WrittenFile;

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
    // Wrapper-integration surface (0.22.0) — the @api BoostSync facade + the
    // SyncResult it returns + the result value objects/enums a wrapper reads,
    // plus the Skill/Guideline payload types a wrapper injects. Promoted so the
    // (already @api) BoostWrapperContract is implementable on frozen surface.
    BoostSync::class,
    SyncResult::class,
    WrittenFile::class,
    EmitterResult::class,
    Diagnostic::class,
    WriteAction::class,
    EmitterAction::class,
    Skill::class,
    Guideline::class,
    // Frontmatter parsing seam (0.22.0) — a wrapper reuses it for boost-tag
    // parity instead of rolling its own YAML-head parse.
    FrontmatterParser::class,
    ParsedDocument::class,
    // Tag-parse seam (0.23.0) — the FrontmatterParser promotion's other half:
    // a wrapper computes the same [tags, valid] (incl. fail-closed) via the
    // canonical path instead of reinventing metadata.boost-tags tokenize+validate.
    BoostTags::class,
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

it('freezes the BoostSync wrapper entry point shape (0.22.0)', function (): void {
    // The @api wrapper-integration facade. Pin make() + sync() so the frozen
    // entry point can't silently drift; the engine behind it stays @internal.
    $rc = new ReflectionClass(BoostSync::class);

    $make = $rc->getMethod('make');
    // `make(): self` — PHP normalizes the `self` return type's reflection name
    // differently across versions (8.3 → "self", 8.5 → the FQCN), so accept both.
    expect($make->isStatic())->toBeTrue()
        ->and($make->getNumberOfRequiredParameters())->toBe(0)
        ->and((string) $make->getReturnType())->toBeIn(['self', BoostSync::class]);

    $sync = $rc->getMethod('sync');
    expect((string) $sync->getReturnType())->toBe(SyncResult::class)
        ->and($sync->getNumberOfRequiredParameters())->toBe(1)
        ->and(array_map(static fn (ReflectionParameter $p): string => $p->getName(), $sync->getParameters()))
        ->toBe(['projectRoot', 'checkOnly', 'injectedVendorSkills', 'extraSkillRenderers', 'injectedVendorGuidelines']);

    // The constructor stays private — wrappers build via make().
    expect($rc->getConstructor()?->isPrivate())->toBeTrue();
});

it('freezes the SyncResult wrapper-read surface (0.22.0)', function (): void {
    // The consumed surface project-boost-laravel reads off a sync result. Pin it
    // so a refactor can't drop a property/method a wrapper depends on.
    $rc = new ReflectionClass(SyncResult::class);

    foreach (['writes', 'emitters', 'errors', 'hostShadows', 'diagnostics'] as $prop) {
        expect($rc->hasProperty($prop))->toBeTrue("SyncResult::\${$prop} is frozen @api");
    }

    foreach (['hasErrors', 'countByAction', 'countEmittersByAction', 'renderDeleteAttribution'] as $method) {
        expect($rc->hasMethod($method))->toBeTrue("SyncResult::{$method}() is frozen @api");
    }

    // The count helpers take the @api enums.
    expect((string) $rc->getMethod('countByAction')->getParameters()[0]->getType())->toBe(WriteAction::class)
        ->and((string) $rc->getMethod('countEmittersByAction')->getParameters()[0]->getType())->toBe(EmitterAction::class);

    // $errors stays a plain `list<string>` — a consumer interpolates each entry
    // directly ("{$err}"), so swapping it to a value object/array would throw
    // "Array to string conversion". Guard the array-ness (the list<string> shape
    // is doc-frozen in the @api docblock).
    expect((string) $rc->getProperty('errors')->getType())->toBe('array');
});

it('freezes @api value-object + method PARAMETER NAMES — the 1.0 named-arg contract (project-boost-laravel)', function (): void {
    // Consumers construct @api value objects and call @api methods with NAMED ARGS,
    // so the parameter NAMES are part of the contract: a rename is breaking even
    // though the @api CLASS is unchanged. Several of these (WrittenFile/EmitterResult/
    // Diagnostic) are read off SyncResult by PROPERTY and never imported, so an
    // import-scanning closure guard can't see them — pin the names by reflection here
    // so a rename or reorder trips CI directly.
    $frozenConstructorParams = [
        Skill::class => ['name', 'description', 'frontmatter', 'body', 'sourcePath', 'sourceVendor', 'tags', 'tagsValid'],
        Guideline::class => ['name', 'description', 'frontmatter', 'body', 'sourcePath', 'sourceVendor', 'tags', 'tagsValid'],
        RenderContext::class => ['sourcePath', 'sourceVendor', 'frontmatter', 'projectRoot'],
        WrittenFile::class => ['relativePath', 'absolutePath', 'action'],
        EmitterResult::class => ['fqcn', 'vendor', 'action', 'relativePath', 'reason'],
        Diagnostic::class => ['level', 'slot', 'message', 'vendor'],
    ];

    foreach ($frozenConstructorParams as $fqcn => $expected) {
        $names = array_map(
            static fn (ReflectionParameter $p): string => $p->getName(),
            (new ReflectionClass($fqcn))->getConstructor()?->getParameters() ?? [],
        );
        expect($names)->toBe($expected, "{$fqcn} constructor param names are a frozen @api named-arg contract");
    }

    // BoostConfig::load — called with named args; param names frozen.
    $loadParams = array_map(
        static fn (ReflectionParameter $p): string => $p->getName(),
        (new ReflectionMethod(BoostConfig::class, 'load'))->getParameters(),
    );
    expect($loadParams)->toBe(['projectRoot', 'configFile']);

    // Diagnostic->level is a STRING contract (`error`|`warning`|`info`), NOT an enum —
    // consumers match raw strings; enum-ifying it within 1.x would break them.
    expect((string) (new ReflectionClass(Diagnostic::class))->getProperty('level')->getType())->toBe('string');
});

it('exposes a string-based @api skill emit-path helper (0.22.0)', function (): void {
    // BoostWrapperContract impls compute emit paths without an @internal Skill.
    $rc = new ReflectionClass(AgentTarget::class);

    $byName = $rc->getMethod('skillRelativePathForName');
    expect(hasInternalTag((string) $byName->getDocComment()))->toBeFalse('skillRelativePathForName is the @api helper')
        ->and((string) $byName->getReturnType())->toBe('string')
        ->and((string) $byName->getParameters()[0]->getType())->toBe('string');

    // The Skill-typed companion stays @internal (it takes the @internal Skill).
    expect(hasInternalTag((string) $rc->getMethod('skillRelativePath')->getDocComment()))->toBeTrue();
});

it('freezes the config exceptions thrown by BoostConfig::load() as @api (project-boost-laravel)', function (): void {
    // A documented @throws of an @api method is part of its contract: a consumer
    // catches the type by name to give a friendly message, so the class must not
    // be renameable under the 1.0 freeze. Mirrors the SkillRenderer exception
    // precedent (Invalid*/SkillRenderException already @api).
    $exceptions = [
        BoostConfigNotFoundException::class,
        InvalidBoostConfigException::class,
        AmbiguousBoostConfigException::class,
    ];

    foreach ($exceptions as $fqcn) {
        $doc = (string) (new ReflectionClass($fqcn))->getDocComment();
        expect($doc)->toContain('@api')
            ->and(hasInternalTag($doc))->toBeFalse("{$fqcn} is a documented @throws of @api BoostConfig::load(); must not be @internal");
    }

    // The docblock must keep naming all three as @throws so the contract is discoverable.
    $loadDoc = (string) (new ReflectionMethod(BoostConfig::class, 'load'))->getDocComment();
    foreach ($exceptions as $fqcn) {
        $short = (new ReflectionClass($fqcn))->getShortName();
        expect($loadDoc)->toContain("@throws {$short}");
    }
});

it('freezes BoostTags NARROW: parse + declaresTags @api, parseString stays @internal (0.23.0, project-boost-laravel)', function (): void {
    // The tag-parse seam — a wrapper computes the canonical [tags, valid] instead
    // of reinventing it (and diverging fail-open on a malformed boost-tags value).
    // The lexer parseString() is NOT part of the frozen promise.
    $rc = new ReflectionClass(BoostTags::class);

    foreach (['parse', 'declaresTags'] as $name) {
        $doc = (string) $rc->getMethod($name)->getDocComment();
        expect(hasInternalTag($doc))->toBeFalse("BoostTags::{$name}() is the frozen @api surface; must not be @internal");
    }

    expect(hasInternalTag((string) $rc->getMethod('parseString')->getDocComment()))
        ->toBeTrue('BoostTags::parseString() is the internal lexer; must carry method-level @internal');

    // parse() returns the frozen [tags, valid] shape the fail-closed contract rides on.
    [$tags, $valid] = BoostTags::parse(['metadata' => ['boost-tags' => ['not', 'a', 'string']]]);
    expect($tags)
        ->toBeEmpty()
        ->and($valid)->toBeFalse('a non-string boost-tags is fail-closed (valid=false)');
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
