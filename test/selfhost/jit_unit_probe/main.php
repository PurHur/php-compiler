<?php

declare(strict_types=1);

/**
 * Self-host JIT unit probe: minimal bundle that parses/loads lib/JIT.php under selfhost AOT.
 * Native: ./script/bootstrap-selfhost-jit-unit-probe.sh (#2332)
 */

require_once __DIR__.'/../../../lib/JIT.php';

if (!class_exists(\PHPCompiler\JIT::class)) {
    throw new \RuntimeException('jit_unit_probe: missing PHPCompiler\\JIT');
}

echo "jit_unit_probe bundle OK\n";
