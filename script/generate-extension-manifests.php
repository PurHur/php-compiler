<?php

declare(strict_types=1);

/**
 * Generate (or --check) per-extension manifests at ext/<name>/ext.json (#36204 / #23480).
 *
 * Manifests are the source of truth for load order, declared dependencies, default-enabled,
 * policy env knobs, and coarse backend support. lib/ExtensionRegistry.php is regenerated from
 * them by script/generate-extension-registry.php.
 *
 * Usage:
 *   php script/generate-extension-manifests.php           # write all ext/<name>/ext.json + docs/extensions.md
 *   php script/generate-extension-manifests.php --check   # fail if any drift
 */

$root = dirname(__DIR__);
$check = in_array('--check', $argv, true);

/**
 * @return list<string>
 */
function extension_registry_order(string $root): array
{
    $registryPath = $root.'/lib/ExtensionRegistry.php';
    if (is_file($registryPath)
        && preg_match_all(
            '/new \\\\PHPCompiler\\\\ext\\\\([A-Za-z0-9_]+)\\\\Module\(\)/',
            (string) file_get_contents($registryPath),
            $rm
        )
    ) {
        return $rm[1];
    }

    $runtimeSrc = (string) file_get_contents($root.'/lib/Runtime.php');
    if (preg_match('/private function loadCoreModules\(\): void \{(.*?)\n    \}/s', $runtimeSrc, $m)
        && preg_match_all('/\$this->load\(new ext\\\\([A-Za-z0-9_]+)\\\\Module\)/', $m[1], $mm)
    ) {
        return $mm[1];
    }

    return [];
}

/**
 * @return list<string>
 */
function module_declared_depends(string $modulePath): array
{
    $src = (string) file_get_contents($modulePath);
    if (!preg_match('/function getExtensionDependencies\(\): array\s*\{\s*return \[(.*?)\];/s', $src, $dm)) {
        return [];
    }
    if (!preg_match_all("/'([^']+)'/", $dm[1], $names)) {
        return [];
    }

    return array_values(array_unique(array_map('strtolower', $names[1])));
}

function module_default_enabled(string $modulePath): bool
{
    $src = (string) file_get_contents($modulePath);
    if (preg_match('/function isDefaultEnabled\(\): bool\s*\{\s*return\s+(true|false)\s*;/s', $src, $m)) {
        return $m[1] === 'true';
    }

    return true;
}

function extension_policy_env(string $extDir, string $name): ?string
{
    $expected = 'PHP_COMPILER_ENABLE_'.strtoupper(str_replace('-', '_', $name));
    foreach (glob($extDir.'/*ExtensionPolicy.php') ?: [] as $policy) {
        $src = (string) file_get_contents($policy);
        if (preg_match("/getenv\\('((?:PHP_COMPILER_ENABLE_)[A-Z0-9_]+)'\\)/", $src, $m)) {
            return $m[1];
        }
        if (str_contains($src, $expected)) {
            return $expected;
        }
    }

    return null;
}

/**
 * @return list<string>
 */
function extension_host_libs(string $extDir): array
{
    $libs = [];
    foreach (glob($extDir.'/*.php') ?: [] as $file) {
        $src = (string) file_get_contents($file);
        if (!preg_match_all("/FFI::cdef\\([^,]+,\\s*'([^']+)'\\)/", $src, $m)) {
            continue;
        }
        foreach ($m[1] as $lib) {
            if ($lib === '' || str_starts_with($lib, '/')) {
                // Absolute paths are host-local; keep the basename soname when obvious.
                $base = basename($lib);
                if ($base !== '' && $base !== $lib) {
                    $libs[] = $base;
                }
                continue;
            }
            $libs[] = $lib;
        }
    }

    $libs = array_values(array_unique($libs));
    sort($libs);

    return $libs;
}

/**
 * @return array{vm: bool, jit: bool, aot: bool}
 */
function extension_backends(string $extDir): array
{
    $hasFfi = false;
    $hasJit = false;
    foreach (glob($extDir.'/*.php') ?: [] as $file) {
        $src = (string) file_get_contents($file);
        if (str_contains($src, 'FFI::cdef') || str_contains($src, 'FFI::load')) {
            $hasFfi = true;
        }
    }
    if ((glob($extDir.'/*JitHelper.php') ?: []) !== []
        || (glob($extDir.'/*Jit.php') ?: []) !== []
    ) {
        $hasJit = true;
    }
    // FFI::cdef is ExternalMethod-null under AOT (#36204) — mark honestly.
    return [
        'vm' => true,
        'jit' => $hasJit || !$hasFfi,
        'aot' => !$hasFfi,
    ];
}

/**
 * @param list<string> $order
 * @return array<string, array<string, mixed>>
 */
function build_manifests(string $root, array $order): array
{
    $byName = [];
    foreach ($order as $i => $name) {
        $extDir = $root.'/ext/'.$name;
        $modulePath = $extDir.'/Module.php';
        if (!is_file($modulePath)) {
            fwrite(STDERR, "generate-extension-manifests: missing Module.php for {$name}\n");
            exit(2);
        }
        $byName[$name] = [
            'name' => $name,
            'load_order' => $i,
            'depends' => module_declared_depends($modulePath),
            'default_enabled' => module_default_enabled($modulePath),
            'host_libs' => extension_host_libs($extDir),
            'backends' => extension_backends($extDir),
            'provides' => [
                'functions' => [],
                'classes' => [],
            ],
            'policy_env' => extension_policy_env($extDir, $name),
        ];
    }

    // Catch Module.php dirs not in the registry (should be none).
    foreach (glob($root.'/ext/*/Module.php') ?: [] as $modulePath) {
        $name = basename(dirname($modulePath));
        if (isset($byName[$name])) {
            continue;
        }
        fwrite(STDERR, "generate-extension-manifests: ext/{$name} has Module.php but is not in ExtensionRegistry order\n");
        exit(2);
    }

    return $byName;
}

/**
 * @param array<string, mixed> $manifest
 */
function encode_manifest(array $manifest): string
{
    return json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
}

/**
 * @param array<string, array<string, mixed>> $manifests
 * @param list<string> $order
 */
function render_extensions_doc(array $manifests, array $order): string
{
    $lines = [];
    $lines[] = '# Extensions';
    $lines[] = '';
    $lines[] = 'Generated by `script/generate-extension-manifests.php` from `ext/<name>/ext.json` (#36204).';
    $lines[] = 'Do not edit by hand — regenerate with that script.';
    $lines[] = '';
    $lines[] = '| name | default | depends | backends | host_libs | policy_env |';
    $lines[] = '|---|---|---|---|---|---|';
    foreach ($order as $name) {
        $m = $manifests[$name];
        $backends = $m['backends'];
        $be = [];
        foreach (['vm', 'jit', 'aot'] as $k) {
            if (!empty($backends[$k])) {
                $be[] = $k;
            }
        }
        $depends = $m['depends'] === [] ? '—' : implode(', ', $m['depends']);
        $libs = $m['host_libs'] === [] ? '—' : implode(', ', $m['host_libs']);
        $policy = $m['policy_env'] ?? '—';
        $default = !empty($m['default_enabled']) ? 'yes' : 'no';
        $lines[] = sprintf(
            '| `%s` | %s | %s | %s | %s | %s |',
            $name,
            $default,
            $depends,
            implode('+', $be),
            $libs,
            $policy === '—' ? '—' : '`'.$policy.'`'
        );
    }
    $lines[] = '';
    $lines[] = sprintf('%d extensions. `aot=false` means the tree uses `FFI::cdef` (null under AOT standalone).', count($order));
    $lines[] = '';

    return implode("\n", $lines);
}

$order = extension_registry_order($root);
if ($order === []) {
    fwrite(STDERR, "generate-extension-manifests: could not determine extension load order\n");
    exit(2);
}

$manifests = build_manifests($root, $order);
$docPath = $root.'/docs/extensions.md';
$doc = render_extensions_doc($manifests, $order);

$drift = [];
foreach ($order as $name) {
    $path = $root.'/ext/'.$name.'/ext.json';
    $encoded = encode_manifest($manifests[$name]);
    $current = is_file($path) ? (string) file_get_contents($path) : '';
    if ($current !== $encoded) {
        $drift[] = 'ext/'.$name.'/ext.json';
        if (!$check) {
            file_put_contents($path, $encoded);
        }
    }
}
$docCurrent = is_file($docPath) ? (string) file_get_contents($docPath) : '';
if ($docCurrent !== $doc) {
    $drift[] = 'docs/extensions.md';
    if (!$check) {
        file_put_contents($docPath, $doc);
    }
}

if ($check) {
    if ($drift === []) {
        printf("generate-extension-manifests: ok — %d manifests + docs/extensions.md current\n", count($order));
        exit(0);
    }
    fwrite(STDERR, "generate-extension-manifests: OUT OF DATE\n");
    foreach ($drift as $path) {
        fwrite(STDERR, "  ".$path."\n");
    }
    fwrite(STDERR, "  run: php script/generate-extension-manifests.php\n");
    exit(1);
}

printf(
    "generate-extension-manifests: wrote %d manifests + docs/extensions.md (%d drifted)\n",
    count($order),
    count($drift)
);
exit(0);
