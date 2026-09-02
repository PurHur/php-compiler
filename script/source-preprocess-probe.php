#!/usr/bin/env php
<?php
/**
 * Assert prepareSourceForParser stays under the #36228 budget on lib/Block.php.
 *
 * Usage: php script/source-preprocess-probe.php [file] [max_ms]
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use PHPCompiler\Runtime;

$file = $argv[1] ?? 'lib/Block.php';
$maxMs = isset($argv[2]) ? (float) $argv[2] : 150.0;

if (!is_file($file)) {
    fwrite(STDERR, "Missing file: {$file}\n");
    exit(1);
}

$code = file_get_contents($file);
$runtime = new Runtime();

$t0 = hrtime(true);
[$out] = $runtime->prepareSourceForParser($code, $file);
$ms = (hrtime(true) - $t0) / 1_000_000;

printf(
    "prepareSourceForParser(%s): %.0f ms (budget %.0f ms), %d -> %d bytes\n",
    $file,
    $ms,
    $maxMs,
    strlen($code),
    strlen($out)
);

if ($ms > $maxMs) {
    fwrite(STDERR, "FAIL: over budget\n");
    exit(1);
}

exit(0);
