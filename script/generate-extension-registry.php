<?php

declare(strict_types=1);

/**
 * Generates lib/ExtensionRegistry.php from ext/<name>/ext.json (#36204 / RELEASE-PLAN Phase 2.5).
 *
 * Per-extension manifests are the source of truth for load_order, depends, and default_enabled.
 * This script emits literal `new \PHPCompiler\ext\{name}\Module()` expressions — the AOT compiler
 * resolves those statically; a dynamic class string would leave modules unreferenced and uncompiled.
 *
 * Order comes from each manifest's load_order (stable across regenerations). Deriving order solely
 * from depends[] is a later step that must be proven equivalent first.
 *
 * Usage:
 *   php script/generate-extension-registry.php            # write lib/ExtensionRegistry.php
 *   php script/generate-extension-registry.php --check    # fail if the file is out of date
 *   php script/generate-extension-registry.php --only=standard,spl,types,ctype,hash,random
 */

$root = dirname(__DIR__);
$check = in_array('--check', $argv, true);
$target = $root.'/lib/ExtensionRegistry.php';

/**
 * Build-time extension selection (RELEASE-PLAN Phase 2.5).
 *
 * Selection has to happen HERE rather than at runtime: the registry emits literal `new` expressions
 * and the AOT compiler resolves them statically, so a module that is referenced is compiled in even
 * if a runtime filter later skips loading it. Dropping the cost means dropping the reference.
 *
 * Identity is the ext/ DIRECTORY. getExtensionName() is not usable — 20 modules report 'standard'.
 */
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
 * @return array{order: list<string>, deps: array<string, list<string>>, default_enabled: array<string, bool>}
 */
$loadManifests = static function () use ($root): array {
    $rows = [];
    foreach (glob($root.'/ext/*/ext.json') ?: [] as $path) {
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data) || !isset($data['name']) || !is_string($data['name'])) {
            fwrite(STDERR, "generate-extension-registry: invalid manifest $path\n");
            exit(2);
        }
        $name = $data['name'];
        $rows[] = [
            'name' => $name,
            'load_order' => isset($data['load_order']) ? (int) $data['load_order'] : PHP_INT_MAX,
            'depends' => array_values(array_map('strtolower', array_map('strval', $data['depends'] ?? []))),
            'default_enabled' => !array_key_exists('default_enabled', $data) || (bool) $data['default_enabled'],
        ];
    }
    if ([] === $rows) {
        return ['order' => [], 'deps' => [], 'default_enabled' => []];
    }
    usort($rows, static function (array $a, array $b): int {
        if ($a['load_order'] === $b['load_order']) {
            return strcmp($a['name'], $b['name']);
        }

        return $a['load_order'] <=> $b['load_order'];
    });
    $order = [];
    $deps = [];
    $defaultEnabled = [];
    foreach ($rows as $row) {
        $order[] = $row['name'];
        $deps[$row['name']] = $row['depends'];
        $defaultEnabled[$row['name']] = $row['default_enabled'];
    }

    return ['order' => $order, 'deps' => $deps, 'default_enabled' => $defaultEnabled];
};

$manifested = $loadManifests();
$order = $manifested['order'];
$declaredDeps = $manifested['deps'];
$defaultEnabled = $manifested['default_enabled'];

if ([] === $order) {
    // Bootstrapping before the first sync-extension-manifests run: fall back to committed registry.
    if (is_file($target)) {
        $existing = (string) file_get_contents($target);
        if (preg_match_all('/new \\\\PHPCompiler\\\\ext\\\\([A-Za-z0-9_]+)\\\\Module\(\)/', $existing, $em)) {
            $order = $em[1];
        }
    }
}

if ([] === $order) {
    fwrite(STDERR, "generate-extension-registry: no ext/<name>/ext.json and no committed registry\n");
    fwrite(STDERR, "  run: php script/sync-extension-manifests.php\n");
    exit(2);
}

// Apply selection, then pull in declared dependencies so a selected extension never loses one.
if (null !== $only || [] !== $without) {
    // Prefer manifest depends; fall back to Module.php for trees mid-migration.
    foreach ($order as $name) {
        if (isset($declaredDeps[$name])) {
            continue;
        }
        $modSrc = @file_get_contents($root.'/ext/'.$name.'/Module.php');
        if (false !== $modSrc
            && preg_match('/function getExtensionDependencies\(\): array\s*\{\s*return \[(.*?)\];/s', $modSrc, $dm)
            && preg_match_all("/'([^']+)'/", $dm[1], $dn)
        ) {
            $declaredDeps[$name] = array_map('strtolower', $dn[1]);
        }
    }

    $selected = null === $only ? $order : array_values(array_intersect($order, $only));
    $selected = array_values(array_diff($selected, $without));
    // Honour default_enabled when selecting the full set via --without only.
    if (null === $only) {
        $selected = array_values(array_filter(
            $selected,
            static fn (string $n): bool => $defaultEnabled[$n] ?? true
        ));
    }

    // Dependency closure: keeping dom must keep libxml, or the build loses it silently.
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

    // Preserve the original relative order — it is load-bearing and only partly declared.
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

// Full manifest maps (all ext/*/ext.json) — ModuleAbstract reads these for deps / default_enabled.
$allDepsLines = [];
$allDefaultLines = [];
foreach ($manifested['order'] as $name) {
    $deps = $manifested['deps'][$name] ?? [];
    if ([] !== $deps) {
        $quoted = array_map(static fn (string $d): string => "'".$d."'", $deps);
        $allDepsLines[] = sprintf("            '%s' => [%s],", $name, implode(', ', $quoted));
    }
    $enabled = $manifested['default_enabled'][$name] ?? true;
    $allDefaultLines[] = sprintf("            '%s' => %s,", $name, $enabled ? 'true' : 'false');
}
$depsBody = [] === $allDepsLines ? '' : "\n".implode("\n", $allDepsLines)."\n        ";
$defaultBody = "\n".implode("\n", $allDefaultLines)."\n        ";

$out = <<<PHP
<?php

declare(strict_types=1);

/**
 * GENERATED by script/generate-extension-registry.php — do not edit by hand.
 *
 * The default extension set and the order it loads in (RELEASE-PLAN Phase 2.5). Regenerate with
 * `php script/generate-extension-registry.php`; `--check` fails when this file is out of date.
 *
 * Order is significant and is copied verbatim from what Runtime::loadCoreModules() did before this
 * file existed. Some constraints are declared on the modules themselves
 * ({@see \\PHPCompiler\\Module::getExtensionDependencies}) and verified by
 * script/check-extension-dependencies.php; others are still only implicit, which is why the order is
 * preserved rather than derived.
 *
 * The entries are literal `new` expressions on purpose: the AOT compiler resolves these statically,
 * and instantiating from a string would leave every module unreferenced and uncompiled.
 *
 * {$count} extensions from ext/<name>/ext.json (#36204). Subset builds use
 * `--only=` / `--without=` on this script; runtime {@see \\PHPCompiler\\Module::isDefaultEnabled}
 * mirrors each manifest's default_enabled via {@see self::isDefaultEnabledFor()}.
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

    /**
     * Declared depends[] from every ext/<name>/ext.json (#36204).
     *
     * Keyed by ext/ directory name (not getExtensionName() — 20 modules report 'standard').
     *
     * @return array<string, list<string>>
     */
    public static function dependenciesByDirectory(): array
    {
        return [{$depsBody}];
    }

    /**
     * default_enabled from every ext/<name>/ext.json (#36204).
     *
     * @return array<string, bool>
     */
    public static function defaultEnabledByDirectory(): array
    {
        return [{$defaultBody}];
    }

    /**
     * @return list<string>
     */
    public static function dependenciesFor(string \$directory): array
    {
        return self::dependenciesByDirectory()[\$directory] ?? [];
    }

    public static function isDefaultEnabledFor(string \$directory): bool
    {
        return self::defaultEnabledByDirectory()[\$directory] ?? true;
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
