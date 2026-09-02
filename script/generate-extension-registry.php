<?php

declare(strict_types=1);

/**
 * Generates lib/ExtensionRegistry.php from ext/<name>/ext.json manifests (#36204 / RELEASE-PLAN Phase 2.5).
 *
 * Manifests are produced by script/generate-extension-manifests.php. Order comes from each
 * manifest's `load_order` field (copied from the historical Runtime::loadCoreModules list) until
 * a proven topological sort lands. Entries remain literal `new` expressions so AOT resolves them.
 *
 * Usage:
 *   php script/generate-extension-registry.php            # write lib/ExtensionRegistry.php
 *   php script/generate-extension-registry.php --check    # fail if the file is out of date
 *   php script/generate-extension-registry.php --only=standard,spl
 *   php script/generate-extension-registry.php --without=intl,gmp
 */

$root = dirname(__DIR__);
$check = in_array('--check', $argv, true);
$target = $root.'/lib/ExtensionRegistry.php';

$only = null;
$without = [];
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--only=')) {
        $only = array_values(array_filter(array_map('trim', explode(',', substr($arg, 7)))));
    } elseif (str_starts_with($arg, '--without=')) {
        $without = array_values(array_filter(array_map('trim', explode(',', substr($arg, 10)))));
    }
}

/**
 * @return array{order: list<string>, depends: array<string, list<string>>}
 */
function load_manifest_order(string $root): array
{
    $manifests = [];
    foreach (glob($root.'/ext/*/ext.json') ?: [] as $path) {
        $name = basename(dirname($path));
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data) || ($data['name'] ?? null) !== $name) {
            fwrite(STDERR, "generate-extension-registry: invalid manifest {$path}\n");
            exit(2);
        }
        if (!isset($data['load_order']) || !is_int($data['load_order'])) {
            fwrite(STDERR, "generate-extension-registry: missing int load_order in {$path}\n");
            exit(2);
        }
        $depends = [];
        if (isset($data['depends']) && is_array($data['depends'])) {
            foreach ($data['depends'] as $dep) {
                if (is_string($dep) && $dep !== '') {
                    $depends[] = strtolower($dep);
                }
            }
        }
        $manifests[$name] = [
            'load_order' => $data['load_order'],
            'depends' => $depends,
            'default_enabled' => !isset($data['default_enabled']) || (bool) $data['default_enabled'],
        ];
    }

    if ($manifests === []) {
        return ['order' => [], 'depends' => []];
    }

    uasort($manifests, static function (array $a, array $b): int {
        return $a['load_order'] <=> $b['load_order'];
    });

    $order = array_keys($manifests);
    $depends = [];
    foreach ($manifests as $name => $info) {
        $depends[$name] = $info['depends'];
    }

    return ['order' => $order, 'depends' => $depends];
}

/**
 * Legacy fallback while manifests are being introduced.
 *
 * @return list<string>
 */
function legacy_order(string $root, string $target): array
{
    $runtimeSrc = (string) file_get_contents($root.'/lib/Runtime.php');
    if (preg_match('/private function loadCoreModules\(\): void \{(.*?)\n    \}/s', $runtimeSrc, $m)
        && preg_match_all('/\$this->load\(new ext\\\\([A-Za-z0-9_]+)\\\\Module\)/', $m[1], $mm)
    ) {
        return $mm[1];
    }
    if (is_file($target)) {
        $existing = (string) file_get_contents($target);
        if (preg_match_all('/new \\\\PHPCompiler\\\\ext\\\\([A-Za-z0-9_]+)\\\\Module\(\)/', $existing, $em)) {
            return $em[1];
        }
    }

    return [];
}

$loaded = load_manifest_order($root);
$order = $loaded['order'];
$declaredDeps = $loaded['depends'];

if ($order === []) {
    $order = legacy_order($root, $target);
    $declaredDeps = [];
    foreach ($order as $name) {
        $modSrc = @file_get_contents($root.'/ext/'.$name.'/Module.php');
        if (false !== $modSrc
            && preg_match('/function getExtensionDependencies\(\): array\s*\{\s*return \[(.*?)\];/s', $modSrc, $dm)
            && preg_match_all("/'([^']+)'/", $dm[1], $dn)
        ) {
            $declaredDeps[$name] = array_map('strtolower', $dn[1]);
        }
    }
}

if ($order === []) {
    fwrite(STDERR, "generate-extension-registry: could not determine the load order\n");
    exit(2);
}

// Every Module.php directory must have a manifest once manifests exist.
$moduleDirs = [];
foreach (glob($root.'/ext/*/Module.php') ?: [] as $modulePath) {
    $moduleDirs[] = basename(dirname($modulePath));
}
sort($moduleDirs);
$manifestNames = $order;
sort($manifestNames);
if ($loaded['order'] !== [] && $moduleDirs !== $manifestNames) {
    $missingManifest = array_diff($moduleDirs, $manifestNames);
    $extraManifest = array_diff($manifestNames, $moduleDirs);
    if ($missingManifest !== []) {
        fwrite(STDERR, 'generate-extension-registry: Module.php without ext.json: '
            .implode(', ', $missingManifest)."\n");
        exit(2);
    }
    if ($extraManifest !== []) {
        fwrite(STDERR, 'generate-extension-registry: ext.json without Module.php: '
            .implode(', ', $extraManifest)."\n");
        exit(2);
    }
}

if (null !== $only || [] !== $without) {
    $selected = null === $only ? $order : array_values(array_intersect($order, $only));
    $selected = array_values(array_diff($selected, $without));

    $changed = true;
    while ($changed) {
        $changed = false;
        foreach ($selected as $name) {
            foreach ($declaredDeps[$name] ?? [] as $dep) {
                if (!in_array($dep, $selected, true) && in_array($dep, $order, true)) {
                    $selected[] = $dep;
                    $changed = true;
                }
            }
        }
    }

    $pulled = array_diff($selected, null === $only ? array_diff($order, $without) : $only);
    if ([] !== $pulled) {
        fwrite(STDERR, 'generate-extension-registry: kept for declared dependencies: '
            .implode(', ', $pulled)."\n");
    }

    $order = array_values(array_filter($order, static fn (string $n): bool => in_array($n, $selected, true)));
}

$missing = [];
foreach ($order as $name) {
    if (!is_file($root.'/ext/'.$name.'/Module.php')) {
        $missing[] = $name;
    }
}
if ([] !== $missing) {
    fwrite(STDERR, 'generate-extension-registry: load list names extensions with no Module.php: '
        .implode(', ', $missing)."\n");
    exit(2);
}

$lines = [];
foreach ($order as $name) {
    $lines[] = sprintf('            new \PHPCompiler\ext\%s\Module(),', $name);
}
$body = implode("\n", $lines);
$count = count($order);

$out = <<<PHP
<?php

declare(strict_types=1);

/**
 * GENERATED by script/generate-extension-registry.php — do not edit by hand.
 *
 * The default extension set and the order it loads in (RELEASE-PLAN Phase 2.5 / #36204).
 * Source of truth: ext/<name>/ext.json (load_order, depends). Regenerate with
 * `php script/generate-extension-registry.php`; `--check` fails when this file is out of date.
 * Refresh manifests first via `php script/generate-extension-manifests.php` when Module.php deps change.
 *
 * Order is significant. `load_order` currently mirrors the historical Runtime::loadCoreModules()
 * list; topological derivation from `depends` is a later step once every edge is declared and
 * verified by script/check-extension-dependencies.php.
 *
 * The entries are literal `new` expressions on purpose: the AOT compiler resolves these statically,
 * and instantiating from a string would leave every module unreferenced and uncompiled.
 *
 * {$count} extensions — matching current behaviour, where every build pays for every
 * default-enabled extension. Selecting a subset uses `--only=` / `--without=` (and later
 * {@see \\PHPCompiler\\Module::isDefaultEnabled}).
 */

namespace PHPCompiler;

final class ExtensionRegistry
{
    /**
     * @return list<Module>
     */
    public static function defaultModules(): array
    {
        return [
{$body}
        ];
    }
}

PHP;

if ($check) {
    $current = is_file($target) ? (string) file_get_contents($target) : '';
    if ($current === $out) {
        printf("generate-extension-registry: ok — lib/ExtensionRegistry.php is current (%d extensions)\n", $count);
        exit(0);
    }
    fwrite(STDERR, "generate-extension-registry: lib/ExtensionRegistry.php is OUT OF DATE\n");
    fwrite(STDERR, "  run: php script/generate-extension-registry.php\n");
    exit(1);
}

file_put_contents($target, $out);
printf("generate-extension-registry: wrote lib/ExtensionRegistry.php (%d extensions)\n", $count);
exit(0);
