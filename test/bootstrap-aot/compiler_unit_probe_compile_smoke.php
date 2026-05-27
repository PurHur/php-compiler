<?php

declare(strict_types=1);

namespace PHPCompiler\BootstrapAot;

/**
 * Zend CFG smoke for compiler unit probe fixture (#2618).
 *
 * @return int 0 when Runtime::parseAndCompile succeeds on the probe fixture
 */
function compiler_unit_probe_compile_smoke(string $sourceFile): int
{
    if (!is_file($sourceFile)) {
        echo 'compiler_unit_probe_compile_smoke: missing source '.$sourceFile."\n";

        return 1;
    }

    $resolved = realpath($sourceFile);
    if (false === $resolved) {
        echo 'compiler_unit_probe_compile_smoke: realpath failed '.$sourceFile."\n";

        return 1;
    }

    $code = file_get_contents($resolved);
    if (!is_string($code) || '' === $code) {
        echo 'compiler_unit_probe_compile_smoke: empty source '.$resolved."\n";

        return 1;
    }

    $runtime = new \PHPCompiler\Runtime(\PHPCompiler\Runtime::MODE_AOT);
    $block = $runtime->parseAndCompileEmitSmoke($code, $resolved);
    if (null === $block) {
        echo "compiler_unit_probe_compile_smoke: parseAndCompileEmitSmoke returned null\n";

        return 1;
    }

    echo "compiler_unit_probe_compile_smoke: CFG OK\n";

    return 0;
}
