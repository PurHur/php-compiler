<?php

declare(strict_types=1);

/**
 * M3 HelloWorld compile driver: Runtime::parseAndCompile + standalone (issue #1056).
 *
 * Lint-only via test/selfhost/compiler_helloworld_smoke/driver_lint.php until the driver
 * can merge into the linkable selfhost bundle (parseAndCompile in bundle OOM/segfault at link).
 */

/**
 * @return array{ok: bool, message: string}
 */
function helloworld_compile_smoke(string $sourceFile, string $outFile): array
{
    if (!is_file($sourceFile)) {
        return ['ok' => false, 'message' => 'helloworld_compile_smoke: missing source '.$sourceFile];
    }

    $resolved = realpath($sourceFile);
    if (false === $resolved) {
        return ['ok' => false, 'message' => 'helloworld_compile_smoke: realpath failed '.$sourceFile];
    }

    $code = file_get_contents($resolved);
    if (!is_string($code) || '' === $code) {
        return ['ok' => false, 'message' => 'helloworld_compile_smoke: empty source '.$resolved];
    }

    $runtime = new \PHPCompiler\Runtime(\PHPCompiler\Runtime::MODE_AOT);
    $block = $runtime->parseAndCompile($code, $resolved);
    if (null === $block) {
        return ['ok' => false, 'message' => 'helloworld_compile_smoke: parseAndCompile returned null'];
    }

    $runtime->standalone($block, $outFile);

    return ['ok' => true, 'message' => 'helloworld_compile_smoke: compile OK -> '.$outFile];
}
