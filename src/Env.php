<?php

declare(strict_types=1);

namespace SanderMuller\BoostCore;

/**
 * Centralised env-var name registry. Anything boost-core reads from the
 * environment lives here so the supported surface is greppable and
 * typo-resistant.
 */
final class Env
{
    /**
     * Set to any non-empty value to skip the plugin's auto-sync pass on
     * `post-autoload-dump` (both project- and global-context branches).
     */
    public const string SKIP_AUTOSYNC = 'BOOST_SKIP_AUTOSYNC';

    /**
     * Set to any non-empty value to skip `.gitignore` management even when
     * `boost.php` opts in via `->withGitignoreManagement(true)`.
     */
    public const string SKIP_GITIGNORE = 'BOOST_SKIP_GITIGNORE';
}
