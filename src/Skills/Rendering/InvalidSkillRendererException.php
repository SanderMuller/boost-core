<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Skills\Rendering;

use RuntimeException;

/**
 * Thrown at dispatcher construction when:
 *  - a renderer returns malformed `extensions()` entries (empty, leading
 *    dot, characters outside `/^[a-z0-9.]+$/`), or
 *  - two registered renderers claim the same extension and the conflict
 *    cannot be resolved (e.g. by `withDisabledRenderers(...)` dropping one).
 */
final class InvalidSkillRendererException extends RuntimeException {}
