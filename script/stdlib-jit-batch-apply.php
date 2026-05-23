#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Conservative batch: patch ext/standard call() to use jitString() for listed builtins.
 *
 * Usage:
 *   php script/stdlib-jit-batch-apply.php names.txt
 *   php script/stdlib-jit-batch-apply.php names.txt --dry-run
 */

$root = dirname(__DIR__);
$dryRun = in_array('--dry-run', $argv, true);
$listFile = null;
foreach ($argv as $i => $arg) {
    if (0 === $i) {
        continue;
    }
    if ('--dry-run' === $arg) {
        continue;
    }
    $listFile = $arg;
    break;
}

if (null === $listFile || !is_file($listFile)) {
    fwrite(STDERR, "Usage: php script/stdlib-jit-batch-apply.php <names.txt> [--dry-run]\n");
    exit(1);
}

$names = [];
foreach (file($listFile, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
    $line = trim($line);
    if ('' === $line || str_starts_with($line, '#')) {
        continue;
    }
    $names[strtolower($line)] = $line;
}
if ([] === $names) {
    fwrite(STDERR, "stdlib-jit-batch-apply: empty name list\n");
    exit(1);
}

$patched = 0;
$skipped = 0;
$missing = [];

foreach (glob($root.'/ext/standard/*.php') as $file) {
    $base = basename($file, '.php');
    if (str_starts_with($base, 'Jit') || str_starts_with($base, 'Vm') || 'Module' === $base) {
        continue;
    }
    $source = (string) file_get_contents($file);
    if (!preg_match('/function\s+call\s*\(/', $source)) {
        continue;
    }
    $fnName = $base;
    if (preg_match("/parent::__construct\\('\\s*([^']+)\\s*'\\)/", $source, $matches)) {
        $fnName = $matches[1];
    }
    if (!isset($names[strtolower($fnName)])) {
        continue;
    }
    unset($names[strtolower($fnName)]);

    if (preg_match('/->jitString\s*\(|JitStringArg::lower/', $source)) {
        ++$skipped;
        fwrite(STDOUT, "skip (already jitString): {$fnName}\n");
        continue;
    }

    $newSource = preg_replace_callback(
        '/(\$context->helper->loadValue\s*\(\s*\$args\[(\d+)\]\s*\))/',
        static function (array $m) use ($fnName): string {
            $idx = $m[2];
            $n = (int) $idx + 1;

            return "\$this->jitString(\$context, \$args[{$idx}], '{$fnName}() argument #{$n}')";
        },
        $source,
        -1,
        $count
    );
    if (!is_string($newSource) || 0 === $count) {
        ++$skipped;
        fwrite(STDOUT, "skip (no loadValue args): {$fnName}\n");
        continue;
    }

    if ($dryRun) {
        fwrite(STDOUT, "would patch {$fnName} ({$count} arg(s))\n");
        ++$patched;
        continue;
    }

    file_put_contents($file, $newSource);
    fwrite(STDOUT, "patched {$fnName} ({$count} arg(s))\n");
    ++$patched;
}

foreach ($names as $name) {
    $missing[] = $name;
}

fwrite(STDOUT, "\nSummary: patched={$patched} skipped={$skipped} missing=".count($missing)."\n");
if ([] !== $missing) {
    fwrite(STDOUT, "Names not found:\n");
    foreach ($missing as $name) {
        fwrite(STDOUT, "  {$name}\n";
    }
}

exit(0);
