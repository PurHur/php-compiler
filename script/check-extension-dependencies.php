<?php

declare(strict_types=1);

/**
 * Checks declared extension dependencies against the real load order (RELEASE-PLAN Phase 2.5).
 *
 * Module::getExtensionDependencies() lets an extension state an ordering constraint that is
 * currently only implicit in the hand-maintained list in Runtime::loadCoreModules(). A declaration
 * nobody verifies is worse than none — it reads as authoritative while being free to drift — so
 * this asserts two things:
 *
 *   1. every declared dependency names an extension that actually exists;
 *   2. every declared dependency is loaded BEFORE its dependent in Runtime::loadCoreModules().
 *
 * It deliberately does not reorder anything. Its job is to prove the declarations describe reality,
 * which is the precondition for later deriving the order from them instead of hand-maintaining it.
 *
 * Usage: php script/check-extension-dependencies.php [--json]
 */

$root = dirname(__DIR__);
$json = in_array('--json', $argv, true);

// The real order, as Runtime loads it.
$runtimeSrc = (string) file_get_contents($root.'/lib/Runtime.php');
$order = [];
if (preg_match('/private function loadCoreModules\(\): void \{(.*?)\n    \}/s', $runtimeSrc, $m)
    && preg_match_all('/\$this->load\(new ext\\\\([A-Za-z0-9_]+)\\\\Module\)/', $m[1], $mm)
) {
    $order = $mm[1];
}
if ([] === $order) {
    fwrite(STDERR, "check-extension-dependencies: could not parse Runtime::loadCoreModules()\n");
    exit(2);
}
$position = array_flip($order);

// Declared dependencies, read from source rather than by loading 76 modules (which would need a
// full Runtime and is far more than this check needs).
$declared = [];
foreach (glob($root.'/ext/*/Module.php') ?: [] as $path) {
    $ext = basename(dirname($path));
    $src = (string) file_get_contents($path);
    if (!preg_match('/function getExtensionDependencies\(\): array\s*\{\s*return \[(.*?)\];/s', $src, $dm)) {
        continue;
    }
    if (!preg_match_all("/'([^']+)'/", $dm[1], $names)) {
        continue;
    }
    $declared[$ext] = array_map('strtolower', $names[1]);
}

$problems = [];
foreach ($declared as $ext => $deps) {
    foreach ($deps as $dep) {
        if (!is_dir($root.'/ext/'.$dep)) {
            $problems[] = sprintf('%s declares dependency "%s" but ext/%s does not exist', $ext, $dep, $dep);
            continue;
        }
        if (!isset($position[$dep])) {
            $problems[] = sprintf('%s declares dependency "%s" but %s is never loaded', $ext, $dep, $dep);
            continue;
        }
        if (!isset($position[$ext])) {
            continue; // the dependent itself is not loaded; not this check's concern
        }
        if ($position[$dep] > $position[$ext]) {
            $problems[] = sprintf(
                '%s (load #%d) declares dependency "%s" but %s loads later at #%d',
                $ext,
                $position[$ext],
                $dep,
                $dep,
                $position[$dep]
            );
        }
    }
}

if ($json) {
    echo json_encode([
        'status' => [] === $problems ? 'ok' : 'fail',
        'declared_count' => count($declared),
        'edge_count' => array_sum(array_map('count', $declared)),
        'loaded_count' => count($order),
        'problems' => $problems,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    exit([] === $problems ? 0 : 1);
}

$edges = array_sum(array_map('count', $declared));
if ([] === $problems) {
    printf(
        "check-extension-dependencies: ok — %d extension(s) declare %d dependency edge(s), all satisfied by the load order\n",
        count($declared),
        $edges
    );
    exit(0);
}

fwrite(STDERR, "check-extension-dependencies: FAILED\n");
foreach ($problems as $p) {
    fwrite(STDERR, '  '.$p."\n");
}
fwrite(STDERR, "\nEither the declaration is wrong or Runtime::loadCoreModules() is ordered wrongly.\n");
exit(1);
