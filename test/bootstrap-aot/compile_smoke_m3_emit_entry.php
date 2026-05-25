<?php

declare(strict_types=1);

/**
 * CLI entry for native M3 compile-smoke emit (issue #1977).
 * Link with PHP_COMPILER_M3_COMPILE_DRIVER=1; dispatch via env (no top-level __DIR__ concat).
 *
 *   PHP_COMPILER_M3_SOURCE=… PHP_COMPILER_M3_OUT=… ./build/selfhost-compile-smoke-emit
 */

require_once __DIR__.'/compile_smoke_m3_emit.php';

$sourceFile = getenv('PHP_COMPILER_M3_SOURCE');
$outFile = getenv('PHP_COMPILER_M3_OUT');
if (!is_string($sourceFile) || '' === $sourceFile || !is_string($outFile) || '' === $outFile) {
    echo "compile_smoke_m3_emit_entry: set PHP_COMPILER_M3_SOURCE and PHP_COMPILER_M3_OUT\n";
    exit(1);
}

exit(\PHPCompiler\BootstrapAot\compile_smoke_m3_emit($sourceFile, $outFile));
