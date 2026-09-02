<?php

declare(strict_types=1);

/**
 * Capability matrix helpers — class-method rows and probed AOT column (#36203).
 *
 * Included from script/capability-matrix.php.
 */

/**
 * Profile + env for doc generation: describe the full in-tree surface, not host-gated runtime.
 */
function capabilityMatrixProfileForDocs(): void
{
    $profile = getenv('PHP_COMPILER_PROFILE');
    if (!\is_string($profile) || '' === trim($profile)) {
        putenv('PHP_COMPILER_PROFILE=8.5');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.5';
    }
}

/**
 * @return array<string, PHPCompiler\Module>
 */
function capabilityMatrixModules(): array
{
    $modules = [];
    foreach (PHPCompiler\ExtensionRegistry::defaultModules() as $module) {
        $label = strtolower($module->getExtensionName());
        $modules[$label] = $module;
    }

    return $modules;
}

/**
 * Index class::method JIT proxies wired in lib/JIT/Context.php.
 *
 * @return array<string, array{handler: string, external: bool}>
 */
function buildJitClassMethodProxyIndex(string $root): array
{
    $index = capabilityMatrixStaticProxyIndex($root);
    capabilityMatrixMergeForeachProxyIndex($root, $index);
    capabilityMatrixMergeInstanceMethodJitIndex($root, $index);

    return $index;
}

/**
 * @return array<string, array{handler: string, external: bool}>
 */
function capabilityMatrixStaticProxyIndex(string $root): array
{
    $contextFile = $root.'/lib/JIT/Context.php';
    if (!is_readable($contextFile)) {
        return [];
    }
    $source = (string) file_get_contents($contextFile);
    $index = [];

    if (preg_match_all(
        '/(?:\$this->)?functionProxies\[\'([^\']+)\'\]\s*=\s*new Call\\\\(\w+)/',
        $source,
        $matches,
        PREG_SET_ORDER
    )) {
        foreach ($matches as $match) {
            $key = strtolower(str_replace('\\\\', '\\', $match[1]));
            if (!str_contains($key, '::')) {
                continue;
            }
            $handler = $match[2];
            $index[$key] = [
                'handler' => $handler,
                'external' => 'ExternalMethod' === $handler,
            ];
        }
    }

    return $index;
}

/**
 * @param array<string, array{handler: string, external: bool}> $index
 */
function capabilityMatrixMergeForeachProxyIndex(string $root, array &$index): void
{
    $contextFile = $root.'/lib/JIT/Context.php';
    if (!is_readable($contextFile)) {
        return;
    }
    $source = (string) file_get_contents($contextFile);
    $methodsByVar = [];

    if (preg_match_all(
        '/foreach\s*\(\s*\[(.*?)\]\s*as\s*\$(\w+)\)/s',
        $source,
        $loops,
        PREG_SET_ORDER
    )) {
        foreach ($loops as $loop) {
            if (!preg_match_all("/'([^']+)'/", $loop[1], $names)) {
                continue;
            }
            $methodsByVar[$loop[2]] = $names[1];
        }
    }

    if (!preg_match_all(
        '/(?:\$this->)?functionProxies\[\'([^\']+)\'\.strtolower\(\$(\w+)\)\]\s*=\s*new Call\\\\(\w+)/',
        $source,
        $assignments,
        PREG_SET_ORDER
    )) {
        return;
    }

    foreach ($assignments as $assignment) {
        $prefix = strtolower(str_replace('\\\\', '\\', $assignment[1]));
        $var = $assignment[2];
        $handler = $assignment[3];
        if (!isset($methodsByVar[$var])) {
            continue;
        }
        foreach ($methodsByVar[$var] as $method) {
            $index[$prefix.strtolower($method)] = [
                'handler' => $handler,
                'external' => 'ExternalMethod' === $handler,
            ];
        }
    }
}

/**
 * @param array<string, array{handler: string, external: bool}> $index
 */
function capabilityMatrixMergeInstanceMethodJitIndex(string $root, array &$index): void
{
    $jitDir = $root.'/lib/JIT';
    if (!is_dir($jitDir)) {
        return;
    }
    foreach (glob($jitDir.'/*InstanceMethodJit.php') ?: [] as $file) {
        $base = basename($file, '.php');
        $source = (string) file_get_contents($file);
        if (!preg_match("/private const METHODS = \[(.*?)\];/s", $source, $m)) {
            continue;
        }
        if (!preg_match_all("/'([^']+::[^']+)'\s*=>/", $m[1], $keys)) {
            continue;
        }
        foreach ($keys[1] as $proxyKey) {
            $key = strtolower(str_replace('\\\\', '\\', $proxyKey));
            $index[$key] = [
                'handler' => $base,
                'external' => false,
            ];
        }
    }
}

function capabilitySourceIndicatesCompileTimeFold(string $source): bool
{
    return 1 === preg_match(
        '/AotFoldState|compileFoldDbId|compile-time fold|CompileTimeFold|compile-time source|user-script AOT requires compile-time/i',
        $source
    );
}

/**
 * Resolve Call\* handler class to primary Jit* lowering source for fold detection.
 */
function capabilityMatrixHandlerLoweringSource(string $root, string $handlerClass): string
{
    if (str_ends_with($handlerClass, 'InstanceMethodJit')) {
        $prefix = str_replace('InstanceMethodJit', '', $handlerClass);
        $chunks = [];
        foreach (glob($root.'/ext/*/Jit'.$prefix.'*.php') ?: [] as $candidate) {
            $chunks[] = (string) file_get_contents($candidate);
        }
        $jitFile = $root.'/lib/JIT/'.$handlerClass.'.php';
        if (is_readable($jitFile)) {
            $chunks[] = (string) file_get_contents($jitFile);
        }
        if ([] !== $chunks) {
            return implode("\n", $chunks);
        }
    }

    foreach (glob($root.'/ext/*/'.$handlerClass.'.php') ?: [] as $candidate) {
        return (string) file_get_contents($candidate);
    }

    $callFile = $root.'/lib/JIT/Call/'.$handlerClass.'.php';
    if (!is_readable($callFile)) {
        return '';
    }
    $callSource = (string) file_get_contents($callFile);
    if (preg_match('/use PHPCompiler\\\\ext\\\\([a-z0-9_]+)\\\\(Jit\w+)/i', $callSource, $m)) {
        $candidate = $root.'/ext/'.$m[1].'/'.$m[2].'.php';
        if (is_readable($candidate)) {
            return (string) file_get_contents($candidate);
        }
    }
    if (preg_match('/use PHPCompiler\\\\JIT\\\\([A-Za-z0-9_]+);/', $callSource, $m)) {
        $candidate = $root.'/lib/JIT/'.$m[1].'.php';
        if (is_readable($candidate)) {
            return (string) file_get_contents($candidate);
        }
    }

    return $callSource;
}

/**
 * @param list<string> $notes
 *
 * @return bool|string
 */
function analyzeInternalAot(PHPCompiler\Func\Internal $fn, bool $jit, array $notes): bool|string
{
    if (!$jit) {
        return false;
    }
    if (\PHPCompiler\JIT\SelfHostBuiltinPolicy::isVmOnlyDeferred($fn->getName())) {
        return false;
    }
    foreach ($notes as $note) {
        if (str_contains($note, 'VM only') || str_contains($note, 'VM-only')) {
            return false;
        }
        if (str_contains($note, 'compile-time JIT deferred')) {
            return false;
        }
    }

    $ref = new ReflectionClass($fn);
    $file = $ref->getFileName();
    $source = false !== $file ? (string) file_get_contents($file) : '';
    if (capabilitySourceIndicatesCompileTimeFold($source)) {
        return 'fold';
    }

    return true;
}

/**
 * @param array<string, array{handler: string, external: bool}> $proxyIndex
 *
 * @return array<string, array{vm: bool, jit: bool, aot: bool|string, notes: list<string>, module: string}>
 */
function collectClassMethodCapabilities(string $root, array $proxyIndex): array
{
    capabilityMatrixProfileForDocs();
    enableOptionalExtsForCapabilityDocs();

    $runtime = new PHPCompiler\Runtime();
    $ctx = $runtime->vmContext;
    $capabilities = [];

    foreach ($ctx->classes as $classLc => $entry) {
        if ($entry->isInterface || $entry->isTrait || !$entry->isInternal) {
            continue;
        }
        if ([] === $entry->methods) {
            continue;
        }

        $module = capabilityMatrixModuleForClass($root, $classLc, $entry->name);

        foreach ($entry->methods as $methodLc => $handler) {
            $displayMethod = $entry->methodNames[$methodLc] ?? $methodLc;
            $rowKey = $entry->name.'::'.$displayMethod;
            $proxyKey = $classLc.'::'.$methodLc;
            $notes = [];
            $jit = false;
            $aot = false;

            if (isset($proxyIndex[$proxyKey])) {
                $proxy = $proxyIndex[$proxyKey];
                if ($proxy['external']) {
                    $notes[] = 'ExternalMethod null-stub under thin AOT (#579)';
                } else {
                    $jit = true;
                    $lowering = capabilityMatrixHandlerLoweringSource($root, $proxy['handler']);
                    if ('' !== $lowering && capabilitySourceIndicatesCompileTimeFold($lowering)) {
                        $aot = 'fold';
                        $notes[] = 'compile-time fold — non-literal inputs fail AOT build (#36203)';
                    } else {
                        $aot = true;
                    }
                }
            } elseif ($handler instanceof PHPCompiler\Func\Internal) {
                $analysis = analyzeInternal($handler);
                $jit = $analysis['jit'];
                $aot = analyzeInternalAot($handler, $jit, $analysis['notes']);
                $notes = $analysis['notes'];
            } else {
                $notes[] = 'VM-only class method handler';
            }

            $capabilities[$rowKey] = [
                'vm' => true,
                'jit' => $jit,
                'aot' => $aot,
                'notes' => $notes,
                'module' => $module,
            ];
        }
    }

    ksort($capabilities, SORT_STRING);

    return $capabilities;
}

function capabilityMatrixModuleForClass(string $root, string $classLc, string $displayName): string
{
    static $cache = null;
    if (null === $cache) {
        $cache = [];
        $extRoot = $root.'/ext';
        if (is_dir($extRoot)) {
            foreach (scandir($extRoot) ?: [] as $dir) {
                if ('.' === $dir || '..' === $dir || !is_dir($extRoot.'/'.$dir)) {
                    continue;
                }
                $builtin = $extRoot.'/'.$dir.'/BuiltinClasses.php';
                if (!is_readable($builtin)) {
                    continue;
                }
                $source = (string) file_get_contents($builtin);
                if (preg_match_all("/registerClass\(\$ctx\)|::registerClass\(\$ctx\)|Vm([A-Za-z0-9_]+)::registerClass/", $source, $m)) {
                    foreach ($m[0] as $i => $_) {
                        if (isset($m[1][$i]) && '' !== $m[1][$i]) {
                            $cache[strtolower($m[1][$i])] = $dir;
                        }
                    }
                }
                if (preg_match_all("/new \\\\PHPCompiler\\\\VM\\\\ClassEntry\('([^']+)'\)/", $source, $m)) {
                    foreach ($m[1] as $className) {
                        $cache[strtolower($className)] = $dir;
                    }
                }
            }
        }
    }

    if (isset($cache[$classLc])) {
        return $cache[$classLc];
    }
    if (isset($cache[strtolower($displayName)])) {
        return $cache[strtolower($displayName)];
    }

    return 'standard';
}

/**
 * @param array<string, array{vm: bool, jit: bool, aot: bool|string, notes: list<string>, module: string}> $functions
 * @param array<string, array{vm: bool, jit: bool, aot: bool|string, notes: list<string>, module: string}> $classMethods
 */
function renderCapabilityMatrixMarkdown(array $functions, array $classMethods, array $phpt): string
{
    $lines = [
        '# Capability matrix',
        '',
        'Auto-generated by `script/capability-matrix.php`. Do not edit by hand.',
        '',
        '## Builtin functions',
        '',
        '| Function | VM | JIT | AOT | Module | Notes |',
        '|----------|:--:|:---:|:---:|--------|-------|',
    ];

    foreach ($functions as $name => $row) {
        $notes = $row['notes'];
        foreach ($phpt['jit'][$name] ?? [] as $tag) {
            $notes[] = $tag;
        }
        foreach ($phpt['aot'][$name] ?? [] as $tag) {
            $notes[] = $tag;
        }
        $notes = array_values(array_unique($notes));
        $lines[] = sprintf(
            '| `%s` | %s | %s | %s | %s | %s |',
            $name,
            capabilityCell($row['vm']),
            capabilityCell($row['jit']),
            capabilityCell($row['aot']),
            $row['module'],
            $notes === [] ? '' : implode('; ', $notes)
        );
    }

    $lines[] = '';
    $lines[] = '## Class methods';
    $lines[] = '';
    $lines[] = 'Internal extension classes only. JIT/AOT from `lib/JIT/Context.php` proxy wiring; `fold` = compile-time literal fold (#36203).';
    $lines[] = '';
    $lines[] = '| Method | VM | JIT | AOT | Module | Notes |';
    $lines[] = '|--------|:--:|:---:|:---:|--------|-------|';

    foreach ($classMethods as $name => $row) {
        $lines[] = sprintf(
            '| `%s` | %s | %s | %s | %s | %s |',
            $name,
            capabilityCell($row['vm']),
            capabilityCell($row['jit']),
            capabilityCell($row['aot']),
            $row['module'],
            [] === $row['notes'] ? '' : implode('; ', $row['notes'])
        );
    }

    $lines[] = '';
    $lines[] = '## Language constructs';
    $lines[] = '';
    $lines[] = 'See [capabilities-syntax.md](capabilities-syntax.md) (generated by `script/capability-syntax.php`):';
    $lines[] = 'classes, methods, visibility, `instanceof`, native user-class link (#568 closed; execute ✅ #764 closed), `match`, arrow functions.';
    $lines[] = '';
    $lines[] = '_Function AOT is probed separately from JIT; class-method AOT follows Context proxy wiring._';
    $lines[] = '';

    return implode("\n", $lines);
}
