#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Refresh only the 006-FileUploadWeb benchmark row in examples/README.md (#2027).
 *
 * Full rebuild-examples.php benches every example (~30+ min); this targets the row
 * check-rebuild-examples-006-row.php guards when LLVM + multipart AOT probe are green.
 *
 * Usage:
 *   BENCH_FILEUPLOADWEB=1 BENCH_FILEUPLOADWEB_AOT=1 php script/refresh-fileupload-web-benchmark-row.php
 */

define('REBUILD_EXAMPLES_LIBRARY_ONLY', true);
require_once __DIR__.'/rebuild-examples.php';

$repoRoot = realpath(__DIR__.'/..') ?: __DIR__.'/..';
putenv('BENCH_FILEUPLOADWEB=1');
putenv('BENCH_FILEUPLOADWEB_AOT=1');

if (!shouldBenchFileUploadWeb($repoRoot)) {
    fwrite(STDERR, "refresh-fileupload-web-benchmark-row: lint gate blocked (BENCH_FILEUPLOADWEB=1 to force)\n");
    exit(1);
}

$llvmReady = isLlvmReady($repoRoot);
$phpCmd = phpCommand();
$benchEnv = benchmarkEnv($repoRoot);
$example = $repoRoot.'/examples/006-FileUploadWeb/example.php';

if (!is_file($example)) {
    fwrite(STDOUT, "refresh-fileupload-web-benchmark-row: OK (006 tree absent)\n");
    exit(0);
}

$row = benchmarkExample($example, $phpCmd, $benchEnv, $repoRoot, $llvmReady);

$readmePath = $repoRoot.'/examples/README.md';
$readme = (string) file_get_contents($readmePath);
if (!preg_match('/^.*\|\s*006-FileUploadWeb\s*\|.*$/mi', $readme)) {
    fwrite(STDERR, "refresh-fileupload-web-benchmark-row: 006-FileUploadWeb row missing\n");
    exit(1);
}

$newReadme = preg_replace('/^.*\|\s*006-FileUploadWeb\s*\|.*$/mi', $row, $readme, 1);
if (null === $newReadme || $newReadme === $readme) {
    fwrite(STDERR, "refresh-fileupload-web-benchmark-row: row replace failed\n");
    exit(1);
}

file_put_contents($readmePath, $newReadme);

fwrite(STDOUT, "refresh-fileupload-web-benchmark-row: OK\n{$row}\n");
