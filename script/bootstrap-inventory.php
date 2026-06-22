#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Bootstrap self-host inventory (issue #212 Phase A).
 *
 * Lists PHP files on the bin/vm.php dependency path and flags language constructs
 * that the static compiler cannot lower yet.
 *
 * Usage:
 *   php script/bootstrap-inventory.php          # write docs/bootstrap-inventory.md
 *   php script/bootstrap-inventory.php --check  # exit 1 if committed doc is stale
 *   php script/bootstrap-inventory.php --json   # machine-readable report on stdout
 */

$root = dirname(__DIR__);
// Inventory generation is used on harness hosts where `vendor/` may be absent.
// Keep vendor autoload when present, but fall back to the repo's own PSR-4 roots.
if (is_file($root.'/vendor/autoload.php')) {
    require $root.'/vendor/autoload.php';
} else {
    if (is_file($root.'/src/macro_functions.php')) {
        require $root.'/src/macro_functions.php';
    }
    spl_autoload_register(static function (string $class) use ($root): void {
        $map = [
            'PHPCompiler\\ext\\' => $root.'/ext/',
            'PHPCompiler\\' => $root.'/lib/',
            'php\\' => $root.'/php/',
        ];
        foreach ($map as $prefix => $dir) {
            if (0 !== strpos($class, $prefix)) {
                continue;
            }
            $rel = substr($class, strlen($prefix));
            $path = $dir.str_replace('\\', '/', $rel).'.php';
            if (is_file($path)) {
                require $path;
            }

            return;
        }
    });
}
require __DIR__.'/bootstrap-lib.php';

$check = in_array('--check', $argv, true);
$jsonOut = in_array('--json', $argv, true);
$outFile = $root.'/docs/bootstrap-inventory.md';

if (!class_exists(\PhpParser\ParserFactory::class)) {
    if ($check) {
        fwrite(STDERR, "bootstrap-inventory: nikic/php-parser missing (vendor/ absent); --check requires composer install (#10531)\n");
        exit(1);
    }
    fwrite(STDERR, "bootstrap-inventory: nikic/php-parser missing (vendor/ absent); cannot regenerate {$outFile}\n");
    fwrite(STDERR, "Hint: run via a dev env with vendor installed, or use docker scripts on a host with network access.\n");
    exit(1);
}

$report = bootstrapCollectInventoryReport($root);

if ($jsonOut) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    exit(0);
}

$markdown = bootstrapRenderMarkdown($report);
if ($check) {
    if (!is_file($outFile)) {
        fwrite(STDERR, "Missing {$outFile}; run: php script/bootstrap-inventory.php\n");
        exit(1);
    }
    $committed = bootstrapStripInventoryProbeSection((string) file_get_contents($outFile));
    $committedNorm = bootstrapNormalizeInventoryLineNumbers($committed);
    $markdownNorm = bootstrapNormalizeInventoryLineNumbers($markdown);
    if ($committedNorm !== $markdownNorm) {
        fwrite(STDERR, "Stale {$outFile}; regenerate with:\n");
        fwrite(STDERR, "  php script/bootstrap-inventory.php\n");
        $parsed = bootstrapParseInventoryMarkdown($committedNorm);
        $diffLines = bootstrapDiffInventoryReport($parsed, $report);
        if ($diffLines !== []) {
            fwrite(STDERR, "\nDiff:\n");
            foreach ($diffLines as $line) {
                fwrite(STDERR, $line."\n");
            }
        }
        fwrite(STDERR, "\nFile-list drift: regenerate committed doc with:\n");
        fwrite(STDERR, "  php script/bootstrap-inventory.php\n");
        fwrite(STDERR, "Optional construct-flag refresh (after self-host probe only — not required for new vm.php-path files): docs/bootstrap-inventory-live-probe.md\n");
        fwrite(STDERR, "  php script/bootstrap-selfhost-compile-probe.php --update-inventory && php script/bootstrap-inventory.php\n");
        exit(1);
    }
    $phaseA = (int) ($report['phase_a']['phase_a_inventory_files'] ?? 0);
    fwrite(STDOUT, "OK {$phaseA}/{$phaseA}\n");
    exit(0);
}

if (!is_dir(dirname($outFile))) {
    mkdir(dirname($outFile), 0775, true);
}
file_put_contents($outFile, $markdown);
$phaseA = $report['phase_a'];
fwrite(
    STDOUT,
    "Wrote {$outFile} ({$report['totals']['files']} files on vm.php path, "
    ."{$phaseA['phase_a_inventory_files']} Phase A inventory files, "
    ."{$report['totals']['blockers']} blockers)\n"
);
