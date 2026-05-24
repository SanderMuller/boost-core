<?php declare(strict_types=1);

namespace SanderMuller\BoostCore;

/**
 * Centralised env-var name registry. Anything boost-core reads from the
 * environment lives here so the supported surface is greppable and
 * typo-resistant.
 */
final class Env
{
    /**
     * Set to any non-empty value to skip auto-sync — honored by the
     * `BoostAutoSync` script callbacks and the `syncUserScope*` helpers.
     */
    public const string SKIP_AUTOSYNC = 'BOOST_SKIP_AUTOSYNC';

    /**
     * Set to any non-empty value to skip `.gitignore` management even when
     * `boost.php` opts in via `->withGitignoreManagement(true)`.
     */
    public const string SKIP_GITIGNORE = 'BOOST_SKIP_GITIGNORE';

    /**
     * Set truthy to escalate any remote-source failure (network unreachable,
     * malformed archive, name-mismatch) to a sync-aborting error. Default
     * is warn-and-skip — read via {@see flagEnabled}, so `0`/`false`/`off`
     * stay disabled (a bare-presence check would flip strict on for those).
     */
    public const string REMOTE_STRICT = 'BOOST_REMOTE_STRICT';

    /**
     * Set truthy to escalate any skill-renderer failure (template syntax
     * error, runtime exception from a custom renderer, …) to a sync-aborting
     * error. Default is warn-and-skip — read via {@see flagEnabled}, so
     * `0`/`false`/`off` stay disabled. Mirrors REMOTE_STRICT but kept
     * separate: render failures and remote-source failures are different
     * failure classes; a project may legitimately want one strict and the
     * other lenient.
     */
    public const string RENDER_STRICT = 'BOOST_RENDER_STRICT';

    /**
     * Truthy-value check for boolean env flags. Returns true only when the
     * env var holds a value users genuinely treat as "on" — `1`, `true`,
     * `yes`, `on` (case-insensitive). Empty, `0`, `false`, `no`, `off`, and
     * unset all return false.
     */
    public static function flagEnabled(string $name): bool
    {
        $raw = (string) getenv($name);

        return in_array(strtolower($raw), ['1', 'true', 'yes', 'on'], true);
    }
}
