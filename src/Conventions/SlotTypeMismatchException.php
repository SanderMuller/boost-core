<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Conventions;

use RuntimeException;

/**
 * @internal
 */
final class SlotTypeMismatchException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $slotPath,
        public readonly string $firstVendor,
        public readonly string $secondVendor,
    ) {
        parent::__construct($message);
    }
}
