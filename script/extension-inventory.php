<?php

declare(strict_types=1);

/**
 * Extension inventory — audits ext/ against the hardcoded load list in Runtime::loadCoreModules().
 *
 * RELEASE-PLAN.md Phase 2.5 wants extensions to become separate, discoverable, side-loadable
 * modules: "a per-extension manifest enumerated at build time, replacing 75 constructor calls".
 * Before any of that can be built safely, we need to know what is actually there — this reports it
 * rather than assuming.
 *
 * Read-only. Prints a table and a summary; --json emits machine-readable output for a future
 * registry generator to consume.
 *
 * Usage:
 *   php script/extension-inventory.php
 *   php script/extension-inventory.php --json
 */

$root = dirname(__DIR__);
$json = in_array('--json', $argv, true);

// 1. Every directory under ext/ that provides a Module entry point.
$onDisk = [];
foreach (scandir($root.'/ext') ?: [] as $entry) {
    if ('.' === $entry || '..' === $entry || !is_dir($root.'/ext/'.$entry)) {
        continue;
    }
    $onDisk[$entry] = is_file($root.'/ext/'.$entry.'/Module.php');
}
ksort($onDisk);

// The real order, from the generated registry (falling back to Runtime on older trees).
$order = [];
$registryPath = $root.'/lib/ExtensionRegistry.php';
if (is_file($registryPath)
    && preg_match_all(
        '/new \\\\PHPCompiler\\\\ext\\\\([A-Za-z0-9_]+)\\\\Module\\(\\)/',
        (string) file_get_contents($registryPath),
        $rm
    )
) {
    $order = $rm[1];
} else {
    // Pre-registry trees still carry the hardcoded list in Runtime::loadCoreModules().
    $runtimeSrc = (string) file_get_contents($root.'/lib/Runtime.php');
    if (preg_match('/private function loadCoreModules\\(\\): void \\{(.*?)\\n    \\}/s', $runtimeSrc, $m)
        && preg_match_all('/\\$this->load\\(new ext\\\\([A-Za-z0-9_]+)\\\\Module\\)/', $m[1], $mm)
    ) {
        $order = $mm[1];
    }
}
$loaded = $order;

// 3. File counts — Phase 2.5 sizes the core/optional split by these.
$fileCount = static function (string $dir): int {
    if (!is_dir($dir)) {
        return 0;
    }
    $n = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile() && 'php' === strtolower($f->getExtension())) {
            ++$n;
        }
    }

    return $n;
};

// The php-src shape RELEASE-PLAN.md adopts: core + ext/standard mandatory, the rest opt-in.
// 'standard' is not in loadCoreModules() because it is wired in separately as the mandatory base.
const MANDATORY = ['standard'];
const SUGGESTED_DEFAULT = ['standard', 'spl', 'types', 'ctype', 'hash', 'random'];

$rows = [];
$loadedSet = array_flip($loaded);
foreach ($onDisk as $name => $hasModule) {
    $rows[$name] = [
        'name' => $name,
        'has_module' => $hasModule,
        'loaded' => isset($loadedSet[$name]),
        'load_order' => isset($loadedSet[$name]) ? $loadedSet[$name] : null,
        'files' => $fileCount($root.'/ext/'.$name),
        'mandatory' => in_array($name, MANDATORY, true),
        'suggested_default' => in_array($name, SUGGESTED_DEFAULT, true),
    ];
}

// Loaded but no directory — would be a broken reference.
$phantom = [];
foreach ($loaded as $name) {
    if (!isset($onDisk[$name])) {
        $phantom[] = $name;
    }
}

$onDiskNotLoaded = [];
$loadedNoModuleFile = [];
foreach ($rows as $name => $r) {
    if ($r['has_module'] && !$r['loaded'] && !$r['mandatory']) {
        $onDiskNotLoaded[] = $name;
    }
    if ($r['loaded'] && !$r['has_module']) {
        $loadedNoModuleFile[] = $name;
    }
}

$totalExtFiles = array_sum(array_column($rows, 'files'));
$defaultFiles = 0;
$optionalFiles = 0;
foreach ($rows as $r) {
    if ($r['suggested_default']) {
        $defaultFiles += $r['files'];
    } else {
        $optionalFiles += $r['files'];
    }
}
$libFiles = $fileCount($root.'/lib');

if ($json) {
    echo json_encode([
        'lib_files' => $libFiles,
        'ext_files_total' => $totalExtFiles,
        'suggested_default_files' => $defaultFiles,
        'optional_files' => $optionalFiles,
        'directories' => count($rows),
        'loaded_count' => count($loaded),
        'load_order' => $loaded,
        'phantom_loads' => $phantom,
        'on_disk_not_loaded' => $onDiskNotLoaded,
        'loaded_without_module_file' => $loadedNoModuleFile,
        'extensions' => array_values($rows),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
}

printf("%-16s %6s  %-8s %-6s %s\n", 'extension', 'files', 'loaded', 'order', 'grouping');
printf("%s\n", str_repeat('-', 62));
foreach ($rows as $name => $r) {
    $group = $r['mandatory'] ? 'mandatory' : ($r['suggested_default'] ? 'default' : 'optional');
    printf(
        "%-16s %6d  %-8s %-6s %s%s\n",
        $name,
        $r['files'],
        $r['loaded'] ? 'yes' : ($r['mandatory'] ? 'base' : 'NO'),
        null === $r['load_order'] ? '-' : (string) $r['load_order'],
        $group,
        $r['has_module'] ? '' : '   (no Module.php)'
    );
}

echo "\n";
printf("directories under ext/          : %d\n", count($rows));
printf("loaded by loadCoreModules()     : %d\n", count($loaded));
printf("lib/ php files                  : %d\n", $libFiles);
printf("ext/ php files                  : %d\n", $totalExtFiles);
printf("  suggested default set         : %d\n", $defaultFiles);
printf("  optional (side-loadable)      : %d\n", $optionalFiles);
printf("core if split as php-src does   : %d  (lib + suggested default)\n", $libFiles + $defaultFiles);

$problems = 0;
if ([] !== $phantom) {
    printf("\nPHANTOM: loaded but no ext/ directory: %s\n", implode(', ', $phantom));
    $problems += count($phantom);
}
if ([] !== $loadedNoModuleFile) {
    printf("\nLOADED WITHOUT Module.php: %s\n", implode(', ', $loadedNoModuleFile));
    $problems += count($loadedNoModuleFile);
}
if ([] !== $onDiskNotLoaded) {
    printf(
        "\nPresent with a Module.php but NOT loaded (%d): %s\n",
        count($onDiskNotLoaded),
        implode(', ', $onDiskNotLoaded)
    );
    echo "  Not necessarily wrong — this is what an opt-in extension set would look like — but today\n";
    echo "  it is implicit, so a new ext/ directory is silently inert until someone edits Runtime.php.\n";
}

exit($problems > 0 ? 1 : 0);
