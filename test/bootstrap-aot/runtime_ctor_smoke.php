<?php

declare(strict_types=1);

namespace PHPCompiler\BootstrapAot;

/**
 * M3 spine slice: Runtime MODE_AOT ctor only (no parseAndCompile / standalone / loadJit).
 *
 * Lint: php bin/compile.php -l test/bootstrap-aot/runtime_ctor_smoke.php
 * Used by helloworld_compile_smoke to surface ctor failures before the full emit chain.
 *
 * Returns int (no assoc arrays) so real-lowered M3 emit avoids __hashtable__readStringKeyValue (#1514).
 */

function runtime_ctor_smoke(): int
{
    if (\function_exists('putenv')) {
        putenv('PHP_COMPILER_SELFHOST_AOT');
    }

    $runtime = new \PHPCompiler\Runtime(\PHPCompiler\Runtime::MODE_AOT);
    if (!isset($runtime->compiler, $runtime->vmContext)) {
        echo "runtime_ctor_smoke: MODE_AOT ctor incomplete (compiler/vmContext spine)\n";
        echo "helloworld_compile_smoke: native emit failed at phase=ctor\n";

        return 1;
    }

    echo "runtime_ctor_smoke: MODE_AOT ctor OK\n";

    return 0;
}
