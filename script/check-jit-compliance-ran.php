#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Fail CI when LLVM is present but JIT compliance (JITTest) ran zero tests.
 *
 * @see https://github.com/PurHur/php-compiler/issues/250
 * @see https://github.com/PurHur/php-compiler/issues/98
 */
if ($argc < 2) {
    fwrite(STDERR, "Usage: {$argv[0]} <junit-xml> [llvm-dir]\n");
    exit(2);
}

$junitPath = $argv[1];
$llvmDir = $argv[2] ?? getenv('PHP_COMPILER_LLVM_PATH') ?: '';

if (!is_file($junitPath)) {
    fwrite(STDERR, "JIT compliance guard: JUnit log not found: {$junitPath}\n");
    exit(1);
}

$xml = @simplexml_load_file($junitPath);
if (false === $xml) {
    fwrite(STDERR, "JIT compliance guard: could not parse JUnit XML: {$junitPath}\n");
    exit(1);
}

$total = 0;
$skipped = 0;
$executed = 0;

/** @var \SimpleXMLElement $testcase */
foreach ($xml->xpath('//testcase') ?: [] as $testcase) {
    $classname = (string) ($testcase['classname'] ?? '');
    $file = (string) ($testcase['file'] ?? '');
    if (!str_contains($classname, 'JITTest') && !str_contains($file, 'JITTest.php')) {
        continue;
    }
    ++$total;
    if (isset($testcase->skipped)) {
        ++$skipped;
    } else {
        ++$executed;
    }
}

if (0 === $total) {
    fwrite(STDERR, "JIT compliance guard: no JITTest cases in {$junitPath}\n");
    fwrite(STDERR, "  Ensure @group llvm includes test/compliance/JITTest.php.\n");
    exit(1);
}

if (0 === $executed) {
    fwrite(STDERR, "JIT compliance guard FAILED: LLVM is present but all {$total} JIT tests were skipped.\n");
    if ('' !== $llvmDir) {
        fwrite(STDERR, "  LLVM dir: {$llvmDir}\n");
    }
    fwrite(STDERR, "  Fix: export PHP_COMPILER_LLVM_PATH to a tree containing libLLVM-9.so.1\n");
    fwrite(STDERR, "       and prepend that directory to LD_LIBRARY_PATH and PATH.\n");
    fwrite(STDERR, "  Docker: use /opt/llvm9 (image #237) — avoid a broken host .llvm/ bind-mount override.\n");
    fwrite(STDERR, "  Override (broken dev env only): PHP_COMPILER_ALLOW_JIT_SKIP=1\n");
    exit(1);
}

fwrite(STDOUT, "JIT compliance guard OK: {$executed} of {$total} JIT tests executed ({$skipped} skipped).\n");
exit(0);
