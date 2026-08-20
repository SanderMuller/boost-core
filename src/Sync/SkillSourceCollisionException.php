<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Sync;

use RuntimeException;

/**
 * Thrown by {@see InjectedVendorMerger} and
 * {@see RemoteSkillSyncCoordinator}
 * when caller-config (injected vendor map, remote source declaration) would
 * silently overwrite an existing entry under the same vendor key.
 *
 * Distinct from {@see CollidingSkillsException}
 * which models cross-vendor name collisions detected by `SkillResolver`.
 * Both are caught in `SyncEngine::sync()` and converted to a `SyncResult`
 * with the message as a sync-level error — never propagated out of `sync()`.
 *
 * @internal
 */
final class SkillSourceCollisionException extends RuntimeException {}
