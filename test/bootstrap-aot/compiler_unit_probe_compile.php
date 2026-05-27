<?php

declare(strict_types=1);

namespace PHPCompiler\BootstrapAot;

/**
 * Zend CFG smoke for compiler unit probe (issue #2618).
 *
 * Lint: php bin/compile.php -l test/bootstrap-aot/compiler_unit_probe_compile.php
 */

function compiler_unit_probe_compile_smoke(string $code, string $filename): bool
{
    $runtime = new \PHPCompiler\Runtime(\PHPCompiler\Runtime::MODE_AOT);
    if (!isset($runtime->compiler)) {
        return false;
    }

    return null !== $runtime->parseAndCompileEmitSmoke($code, $filename);
}
