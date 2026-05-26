<?php

declare(strict_types=1);

/**
 * M3 PHPTypes unit probe: JIT bundle that seeds \PHPTypes\Type external-class constants (#2430).
 * Gate: php bin/compile.php -l test/selfhost/types_unit_probe/main.php
 * Native: ./script/bootstrap-selfhost-types-unit-probe.sh
 * Zend: types_unit_probe_types_smoke() in types_unit_probe_types.php (PHPUnit).
 */

require_once __DIR__.'/../../../lib/JIT.php';

if (!class_exists(\PHPCompiler\JIT::class)) {
    throw new \RuntimeException('types_unit_probe: missing PHPCompiler\\JIT');
}

if (!class_exists(\PHPCompiler\JIT\Builtin\Type::class)) {
    throw new \RuntimeException('types_unit_probe: missing PHPCompiler\\JIT\\Builtin\\Type');
}

echo "types_unit_probe bundle OK\n";
