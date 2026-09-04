#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Build a gen-0 split-TU chunk plan for parallel/resumable emit (#36387 / #36147).
 *
 * Modes (combinable):
 *   --micro[=N]          N tiny echo fixtures (default 4) — gate/orchestrator smoke
 *   --ext=a,b,c          one chunk per extension directory
 *   --lib=AOT,Lint       one chunk per lib/<name> directory (small capacity-safe TUs)
 *   --requires=PATH      hub chunk from a requires list (e.g. spine-chunk-core-requires.txt)
 *   --spine              partition test/selfhost/compiler_lib_spine_smoke/main.php
 *   --strategy=dir|sub|hub   spine partition strategy (default dir; see spine-split-probe.php)
 *   --max-files=N        further split any bucket larger than N files (capacity under 8g)
 *   --plan-out=PATH      write JSON (default stdout)
 *   --entries-dir=D      where generated entry .php files go (default build/chunks/entries)
 *
 * Chunks carry wave=0 for hubs/requires (emit first so peer manifests exist) and higher
 * waves for lib/ext/spine consumers. Consumed by script/bootstrap-gen0-chunks.sh.
 */

$root = dirname(__DIR__);
$micro = null;
$exts = [];
$libs = [];
$requiresFiles = [];
$spine = false;
$strategy = 'dir';
$maxFiles = 0;
$planOut = null;
$entriesDir = $root.'/build/chunks/entries';

/** Directories oversized enough to need letter/hub sub-splits (mirrors spine-split-probe). */
const SPINE_SUBSPLIT = [
    'ext/standard' => true,
    'lib/JIT' => true,
    'lib/VM' => true,
];

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--micro' || str_starts_with($arg, '--micro=')) {
        $micro = $arg === '--micro' ? 4 : max(1, (int) substr($arg, 8));
        continue;
    }
    if ($arg === '--spine') {
        $spine = true;
        continue;
    }
    if (str_starts_with($arg, '--strategy=')) {
        $strategy = substr($arg, 11);
        if (!in_array($strategy, ['dir', 'sub', 'hub', 'top', 'ext'], true)) {
            fwrite(STDERR, "bootstrap-gen0-chunk-plan: unknown strategy {$strategy}\n");
            exit(2);
        }
        continue;
    }
    if (str_starts_with($arg, '--max-files=')) {
        $maxFiles = max(0, (int) substr($arg, 12));
        continue;
    }
    if (str_starts_with($arg, '--ext=')) {
        foreach (explode(',', substr($arg, 6)) as $name) {
            $name = trim($name);
            if ($name !== '') {
                $exts[] = $name;
            }
        }
        continue;
    }
    if (str_starts_with($arg, '--lib=')) {
        foreach (explode(',', substr($arg, 6)) as $name) {
            $name = trim($name);
            if ($name !== '') {
                $libs[] = $name;
            }
        }
        continue;
    }
    if (str_starts_with($arg, '--requires=')) {
        $path = substr($arg, 11);
        if ($path !== '' && $path[0] !== '/') {
            $path = $root.'/'.$path;
        }
        $requiresFiles[] = $path;
        continue;
    }
    if (str_starts_with($arg, '--plan-out=')) {
        $planOut = substr($arg, 11);
        continue;
    }
    if (str_starts_with($arg, '--entries-dir=')) {
        $entriesDir = substr($arg, 14);
        if ($entriesDir !== '' && $entriesDir[0] !== '/') {
            $entriesDir = $root.'/'.$entriesDir;
        }
        continue;
    }
    fwrite(STDERR, "bootstrap-gen0-chunk-plan: unknown argument {$arg}\n");
    exit(2);
}

if ($micro === null && $exts === [] && $libs === [] && $requiresFiles === [] && !$spine) {
    fwrite(STDERR, "bootstrap-gen0-chunk-plan: pass --micro[=N], --ext=, --lib=, --requires=, and/or --spine\n");
    exit(2);
}

if (!is_dir($entriesDir) && !mkdir($entriesDir, 0755, true) && !is_dir($entriesDir)) {
    fwrite(STDERR, "bootstrap-gen0-chunk-plan: cannot create {$entriesDir}\n");
    exit(1);
}

/**
 * Relative path prefix from $fromDir up to $root (always ends with /).
 */
$relPrefixToRoot = static function (string $fromDir, string $rootPath): string {
    $from = realpath($fromDir) ?: $fromDir;
    $to = realpath($rootPath) ?: $rootPath;
    $from = str_replace('\\', '/', $from);
    $to = str_replace('\\', '/', $to);
    if (str_starts_with($from, $to.'/')) {
        $depth = substr_count(substr($from, strlen($to) + 1), '/') + 1;

        return str_repeat('../', $depth);
    }
    // Fallback: walk parents until root matches.
    $prefix = '';
    $cur = $from;
    for ($i = 0; $i < 32; ++$i) {
        if ($cur === $to || realpath($cur) === realpath($to)) {
            return $prefix !== '' ? $prefix : './';
        }
        $prefix .= '../';
        $parent = dirname($cur);
        if ($parent === $cur) {
            break;
        }
        $cur = $parent;
    }

    return '../../../';
};

$entriesRelPrefix = $relPrefixToRoot($entriesDir, $root);

/**
 * @param list<string> $rels repo-relative .php paths
 */
$writeAutoloadEntry = static function (string $entry, string $comment, array $rels) use ($root, $entriesRelPrefix): void {
    $lines = [
        '<?php',
        '',
        '// '.$comment,
        '// Autoloader first so Zend can load ModuleAbstract / Func\\Internal (spine-chunk-probe trap #1).',
        '',
        "require_once __DIR__ . '/{$entriesRelPrefix}vendor/autoload.php';",
    ];
    foreach ($rels as $rel) {
        $abs = $root.'/'.$rel;
        if (!is_file($abs)) {
            fwrite(STDERR, "bootstrap-gen0-chunk-plan: missing require {$rel}\n");
            exit(1);
        }
        $lines[] = "require_once __DIR__ . '/{$entriesRelPrefix}{$rel}';";
    }
    $lines[] = '';
    if (file_put_contents($entry, implode("\n", $lines)."\n") === false) {
        fwrite(STDERR, "bootstrap-gen0-chunk-plan: cannot write {$entry}\n");
        exit(1);
    }
};

/** Sanitize a chunk key into a filesystem-safe id. */
$chunkIdOf = static function (string $key): string {
    $id = preg_replace('/[^A-Za-z0-9]+/', '-', $key) ?? $key;
    $id = trim($id, '-');

    return $id !== '' ? strtolower($id) : 'chunk';
};

/**
 * Collect .php files under a directory as repo-relative paths.
 *
 * @return list<string>
 */
$collectDirRels = static function (string $srcDir) use ($root): array {
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcDir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $fileInfo) {
        if (!$fileInfo->isFile() || $fileInfo->getExtension() !== 'php') {
            continue;
        }
        $files[] = substr($fileInfo->getPathname(), strlen($root) + 1);
    }
    sort($files, SORT_STRING);

    return $files;
};

/**
 * Split a list into batches of at most $max files (0 = no split).
 *
 * @param list<string> $rels
 * @return list<list<string>>
 */
$batchRels = static function (array $rels, int $max): array {
    if ($max < 1 || count($rels) <= $max) {
        return [$rels];
    }
    $batches = [];
    for ($i = 0, $n = count($rels); $i < $n; $i += $max) {
        $batches[] = array_slice($rels, $i, $max);
    }

    return $batches;
};

/**
 * Spine partition key for a repo-relative path (same rules as spine-split-probe.php).
 */
$chunkOf = static function (string $rel) use ($strategy): string {
    $parts = explode('/', $rel);
    $dir = count($parts) > 2 ? $parts[0].'/'.$parts[1] : $parts[0];

    return match ($strategy) {
        'top' => $parts[0],
        'ext' => 'ext' === $parts[0] ? 'ext/'.($parts[1] ?? '_') : 'lib',
        'sub' => isset(SPINE_SUBSPLIT[$dir])
            ? $dir.'#'.strtoupper(substr(basename($rel), 0, 1))
            : $dir,
        'hub' => isset(SPINE_SUBSPLIT[$dir])
            ? (str_starts_with(basename($rel), 'Vm')
                ? $dir.'#hub'
                : $dir.'#'.strtoupper(substr(basename($rel), 0, 1)))
            : $dir,
        default => $dir,
    };
};

$chunks = [];

if ($micro !== null) {
    for ($i = 0; $i < $micro; $i++) {
        $id = sprintf('micro-%02d', $i);
        $entry = $entriesDir.'/'.$id.'.php';
        $body = "<?php\n"
            ."// Generated by bootstrap-gen0-chunk-plan.php — micro split-TU fixture (#36387).\n"
            .'echo "chunk-'.$id.'\n";'."\n";
        if (file_put_contents($entry, $body) === false) {
            fwrite(STDERR, "bootstrap-gen0-chunk-plan: cannot write {$entry}\n");
            exit(1);
        }
        $chunks[] = [
            'chunk_id' => $id,
            'entry' => $entry,
            'kind' => 'micro',
            'wave' => 0,
            'file_count' => 1,
        ];
    }
}

foreach ($requiresFiles as $reqPath) {
    if (!is_file($reqPath)) {
        fwrite(STDERR, "bootstrap-gen0-chunk-plan: missing requires file {$reqPath}\n");
        exit(1);
    }
    $base = pathinfo($reqPath, PATHINFO_FILENAME);
    $base = preg_replace('/^spine-chunk-|-requires$/', '', $base) ?? $base;
    $id = 'hub-'.($chunkIdOf($base !== '' ? $base : 'requires'));
    $rels = [];
    foreach (file($reqPath, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $rel = trim(preg_replace('/#.*/', '', $line) ?? '');
        if ($rel === '') {
            continue;
        }
        $rels[] = $rel;
    }
    if ($rels === []) {
        fwrite(STDERR, "bootstrap-gen0-chunk-plan: empty requires file {$reqPath}\n");
        exit(1);
    }
    $entry = $entriesDir.'/'.$id.'.php';
    $writeAutoloadEntry(
        $entry,
        'Generated by bootstrap-gen0-chunk-plan.php — hub from '.substr($reqPath, strlen($root) + 1).' (#36387).',
        $rels
    );
    $chunks[] = [
        'chunk_id' => $id,
        'entry' => $entry,
        'kind' => 'hub',
        'wave' => 0,
        'file_count' => count($rels),
        'requires' => $reqPath,
    ];
}

foreach ($libs as $lib) {
    $lib = ltrim($lib, '/');
    if (str_starts_with($lib, 'lib/')) {
        $lib = substr($lib, 4);
    }
    $srcDir = $root.'/lib/'.$lib;
    if (!is_dir($srcDir)) {
        fwrite(STDERR, "bootstrap-gen0-chunk-plan: missing lib directory {$srcDir}\n");
        exit(1);
    }
    $rels = $collectDirRels($srcDir);
    if ($rels === []) {
        fwrite(STDERR, "bootstrap-gen0-chunk-plan: no .php files under lib/{$lib}\n");
        exit(1);
    }
    $batches = $batchRels($rels, $maxFiles);
    foreach ($batches as $bi => $batch) {
        $id = 'lib-'.$chunkIdOf($lib);
        if (count($batches) > 1) {
            $id .= sprintf('-%02d', $bi);
        }
        $entry = $entriesDir.'/'.$id.'.php';
        $writeAutoloadEntry(
            $entry,
            'Generated by bootstrap-gen0-chunk-plan.php — lib/'.$lib.' split-TU (#36387).',
            $batch
        );
        $chunks[] = [
            'chunk_id' => $id,
            'entry' => $entry,
            'kind' => 'lib',
            'wave' => 1,
            'file_count' => count($batch),
        ];
    }
}

foreach ($exts as $ext) {
    $srcDir = $root.'/ext/'.$ext;
    if (!is_dir($srcDir)) {
        fwrite(STDERR, "bootstrap-gen0-chunk-plan: missing extension {$srcDir}\n");
        exit(1);
    }
    $rels = $collectDirRels($srcDir);
    if ($rels === []) {
        fwrite(STDERR, "bootstrap-gen0-chunk-plan: no .php files under ext/{$ext}\n");
        exit(1);
    }
    $batches = $batchRels($rels, $maxFiles);
    foreach ($batches as $bi => $batch) {
        $id = 'ext-'.$chunkIdOf($ext);
        if (count($batches) > 1) {
            $id .= sprintf('-%02d', $bi);
        }
        $entry = $entriesDir.'/'.$id.'.php';
        $writeAutoloadEntry(
            $entry,
            'Generated by bootstrap-gen0-chunk-plan.php — spine chunk TU for ext/'.$ext.' (#36387).',
            $batch
        );
        $chunks[] = [
            'chunk_id' => $id,
            'entry' => $entry,
            'kind' => 'ext',
            'wave' => 2,
            'file_count' => count($batch),
        ];
    }
}

if ($spine) {
    $spineEntry = $root.'/test/selfhost/compiler_lib_spine_smoke/main.php';
    if (!is_file($spineEntry)) {
        fwrite(STDERR, "bootstrap-gen0-chunk-plan: missing spine entry {$spineEntry}\n");
        exit(1);
    }
    $spineRels = [];
    foreach (file($spineEntry, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (preg_match("#require_once __DIR__\\.'(?:/\\.\\.)+/([^']+)'#", $line, $m)) {
            $spineRels[] = $m[1];
        }
    }
    $spineRels = array_values(array_unique($spineRels));
    if ($spineRels === []) {
        fwrite(STDERR, "bootstrap-gen0-chunk-plan: no require_once paths in spine entry\n");
        exit(1);
    }
    /** @var array<string, list<string>> $buckets */
    $buckets = [];
    foreach ($spineRels as $rel) {
        if (!str_ends_with($rel, '.php')) {
            continue;
        }
        $key = $chunkOf($rel);
        $buckets[$key][] = $rel;
    }
    ksort($buckets, SORT_STRING);
    foreach ($buckets as $key => $rels) {
        $batches = $batchRels($rels, $maxFiles);
        foreach ($batches as $bi => $batch) {
            $id = 'spine-'.$chunkIdOf($key);
            if (count($batches) > 1) {
                $id .= sprintf('-%02d', $bi);
            }
            $entry = $entriesDir.'/'.$id.'.php';
            $writeAutoloadEntry(
                $entry,
                'Generated by bootstrap-gen0-chunk-plan.php — spine partition '.$key
                    .' strategy='.$strategy.' (#36387).',
                $batch
            );
            $chunks[] = [
                'chunk_id' => $id,
                'entry' => $entry,
                'kind' => 'spine',
                'wave' => 2,
                'file_count' => count($batch),
                'partition' => $key,
                'strategy' => $strategy,
            ];
        }
    }
}

// Stable order: wave ascending, then chunk_id.
usort($chunks, static function (array $a, array $b): int {
    $wa = (int) ($a['wave'] ?? 0);
    $wb = (int) ($b['wave'] ?? 0);
    if ($wa !== $wb) {
        return $wa <=> $wb;
    }

    return strcmp((string) $a['chunk_id'], (string) $b['chunk_id']);
});

$plan = [
    'version' => 2,
    'generated_at' => gmdate('c'),
    'root' => $root,
    'entries_dir' => $entriesDir,
    'strategy' => $spine ? $strategy : null,
    'max_files' => $maxFiles > 0 ? $maxFiles : null,
    'chunk_count' => count($chunks),
    'chunks' => $chunks,
    'note' => 'Consumed by script/bootstrap-gen0-chunks.sh. Wave 0 hubs emit first so peer '
        .'manifests can bind consumers (#36387 / #36155 Phase C).',
];

$json = json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
if ($planOut === null || $planOut === '-' || $planOut === '') {
    fwrite(STDOUT, $json);
    exit(0);
}
$dir = dirname($planOut);
if ($dir !== '' && $dir !== '.' && !is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
    fwrite(STDERR, "bootstrap-gen0-chunk-plan: cannot create {$dir}\n");
    exit(1);
}
if (file_put_contents($planOut, $json) === false) {
    fwrite(STDERR, "bootstrap-gen0-chunk-plan: cannot write {$planOut}\n");
    exit(1);
}
fwrite(STDOUT, "bootstrap-gen0-chunk-plan: wrote {$planOut} ({$plan['chunk_count']} chunks)\n");
exit(0);
