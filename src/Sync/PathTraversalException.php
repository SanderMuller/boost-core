<?php

declare(strict_types=1);

namespace SanderMuller\BoostCore\Sync;

use RuntimeException;

final class PathTraversalException extends RuntimeException
{
    public function __construct(
        public readonly string $attemptedPath,
        public readonly string $projectRoot,
    ) {
        parent::__construct(sprintf(
            'Refusing to write outside project root. Path "%s" escapes "%s".',
            $attemptedPath,
            $projectRoot,
        ));
    }
}
