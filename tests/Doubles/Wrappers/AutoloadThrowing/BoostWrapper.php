<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Tests\Doubles\Wrappers\AutoloadThrowing;

use RuntimeException;

// Test double for 0.11.0 codex-review P1 — simulates a wrapper whose
// `BoostWrapper.php` throws during autoload (parse error / top-level throw /
// failed dependency at load time). The top-level throw fires when the
// autoloader includes this file for `class_exists()`, which would abort the
// entire sync without the engine's try/catch around the probe.
//
// No class is ever declared: the throw fires first. Only loaded lazily when
// the discovery probe references the FQN — never eager-loaded by Composer's
// PSR-4 map or by PHPStan/Pint static analysis.
throw new RuntimeException('wrapper boom on autoload');
