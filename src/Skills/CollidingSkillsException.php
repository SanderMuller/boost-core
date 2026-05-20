<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Skills;

use RuntimeException;

final class CollidingSkillsException extends RuntimeException
{
    /**
     * @param  string  $name  The colliding skill/guideline name.
     * @param  list<string>  $vendors  Vendors that all publish this name.
     */
    public function __construct(
        public readonly string $name,
        public readonly array $vendors,
    ) {
        parent::__construct(sprintf(
            'Skill "%s" is published by multiple vendors: %s. Host can override; vendor-vs-vendor collisions require --force.',
            $name,
            implode(', ', $vendors),
        ));
    }
}
