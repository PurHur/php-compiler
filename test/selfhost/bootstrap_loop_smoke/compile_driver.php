<?php

declare(strict_types=1);

/**
 * M4 gen-1 native compile driver (linkable; runtime dispatch via env — issue #1498).
 *
 * Gate: php bin/compile.php -l test/selfhost/bootstrap_loop_smoke/compile_driver.php
 *
 * Compile mode (maps M4 env → M3 helloworld driver; avoid top-level __DIR__ concat):
 *   PHP_COMPILER_M4_COMPILE_MODE=compile PHP_COMPILER_M4_RUNTIME_COMPILE=1
 *   PHP_COMPILER_M4_SOURCE=… PHP_COMPILER_M4_OUT=… ./build/bootstrap-loop-gen1-compile
 */

if ('compile' === (string) getenv('PHP_COMPILER_M4_COMPILE_MODE')) {
    $sourceFile = getenv('PHP_COMPILER_M4_SOURCE');
    $outFile = getenv('PHP_COMPILER_M4_OUT');
    if (is_string($sourceFile) && '' !== $sourceFile) {
        putenv('PHP_COMPILER_M3_SOURCE='.$sourceFile);
    }
    if (is_string($outFile) && '' !== $outFile) {
        putenv('PHP_COMPILER_M3_OUT='.$outFile);
    }
    putenv('PHP_COMPILER_M3_COMPILE_MODE=compile');
    if ('1' === (string) getenv('PHP_COMPILER_M4_RUNTIME_COMPILE')) {
        putenv('PHP_COMPILER_M3_RUNTIME_COMPILE=1');
    }
}

require_once __DIR__.'/../compiler_helloworld_smoke/compile_driver.php';

if ('compile' !== (string) getenv('PHP_COMPILER_M4_COMPILE_MODE')
    && 'compile' !== (string) getenv('PHP_COMPILER_M3_COMPILE_MODE')) {
    echo "bootstrap_loop_compile_driver ready (delegates to M3 helloworld compile driver)\n";
}
