<?php

declare(strict_types=1);

/**
 * Generates lib/ExtensionRegistry.php from the load list in Runtime::loadCoreModules().
 *
 * RELEASE-PLAN Phase 2.5: "a per-extension manifest enumerated at build time, replacing 75
 * constructor calls". This is the first half — move the list out of a hand-maintained method in a
 * core file and into a generated one, WITHOUT changing the order.
 *
 * Two deliberate constraints:
 *
 *   1. The generated file emits literal `new \PHPCompiler\ext\<name>\Module()` expressions, not
 *      dynamic instantiation from strings. The AOT compiler resolves these statically; a
 *      `new $className` would leave the modules unreferenced and they would not be compiled in.
 *
 *   2. The order is copied verbatim from the current hardcoded list. Ordering constraints here are
 *      real (libxml before dom before xsl) and partly still undeclared, so deriving a fresh order
 *      from dependencies is a separate, later step that must be proven equivalent first.
 *
 * Usage:
 *   php script/generate-extension-registry.php            # write lib/ExtensionRegistry.php
 *   php script/generate-extension-registry.php --check    # fail if the file is out of date
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

$runtimeSrc = (string) file_get_contents($root.'/lib/Runtime.php');

// Prefer the hardcoded list while it still exists; once Runtime consumes the registry, the
// committed registry is the source of truth and regeneration is a no-op that still verifies.
$order = [];
if (preg_match('/private function loadCoreModules\(\): void \{(.*?)\n    \}/s', $runtimeSrc, $m)
    && preg_match_all('/\$this->load\(new ext\\\\([A-Za-z0-9_]+)\\\\Module\)/', $m[1], $mm)
) {
    $order = $mm[1];
}

if ([] === $order && is_file($target)) {
    // Runtime already delegates; re-read the committed registry so --check stays meaningful.
    $existing = (string) file_get_contents($target);
    if (preg_match_all('/new \\\\PHPCompiler\\\\ext\\\\([A-Za-z0-9_]+)\\\\Module\(\)/', $existing, $em)) {
        $order = $em[1];
    }
}

if ([] === $order) {
    fwrite(STDERR, "generate-extension-registry: could not determine the load order\n");
    exit(2);
}

// Apply selection, then pull in declared dependencies so a selected extension never loses one.
if (null !== $only || [] !== $without) {
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

    $selected = null === $only ? $order : array_values(array_intersect($order, $only));
    $selected = array_values(array_diff($selected, $without));

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
 * {$count} extensions, all default-enabled — matching current behaviour, where every build pays for
 * every extension. Selecting a subset is the next step and will filter on
 * {@see \\PHPCompiler\\Module::isDefaultEnabled}.
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
