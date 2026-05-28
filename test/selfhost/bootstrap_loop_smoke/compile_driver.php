<?php

declare(strict_types=1);

/**
 * M4 gen-1 inventory emit entry — delegates to M3 helloworld compile driver (#2893).
 *
 * Gate: php bin/compile.php -l test/selfhost/bootstrap_loop_smoke/compile_driver.php
 *
 * Native link: bootstrap-loop-gen1-link.sh links compiler_helloworld_smoke/compile_driver.php
 * (this file is lint/M4 env alias; wrapper {main} must not be the inventory link TU).
 *
 * M4 env (optional; gen-1-link sets PHP_COMPILER_M3_* before invoke):
 *   PHP_COMPILER_M4_COMPILE_MODE=compile PHP_COMPILER_M4_RUNTIME_COMPILE=1
 *   PHP_COMPILER_M4_SOURCE=… PHP_COMPILER_M4_OUT=…
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
