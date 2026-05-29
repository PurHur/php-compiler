#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Generate docs/capabilities.md from ext module registrations, opcode handlers, and PHPT coverage.
 *
 * Language constructs (classes, match, …) are in docs/capabilities-syntax.md via capability-syntax.php.
 *
 * Usage:
 *   php script/capability-matrix.php          # write docs/capabilities.md
 *   php script/capability-matrix.php --check  # exit 1 if committed file is stale
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require __DIR__ . '/capability-syntax-lib.php';

$check = in_array('--check', $argv, true);
$outFile = $root . '/docs/capabilities.md';

/** @return array<string, array{vm: bool, jit: bool, aot: bool, notes: list<string>, module: string}> */
function collectCapabilities(string $root): array
{
    $modules = [
        'types' => new PHPCompiler\ext\types\Module(),
        'standard' => new PHPCompiler\ext\standard\Module(),
    ];

    $capabilities = [];

    foreach ($modules as $moduleLabel => $module) {
        foreach ($module->getFunctions() as $fn) {
            if (!$fn instanceof PHPCompiler\Func\Internal) {
                continue;
            }
            $name = $fn->getName();
            $analysis = analyzeInternal($fn);
            $capabilities[$name] = [
                'vm' => true,
                'jit' => $analysis['jit'],
                'aot' => $analysis['jit'],
                'notes' => $analysis['notes'],
                'module' => $moduleLabel,
            ];
        }
    }

    ksort($capabilities, SORT_STRING);

    return $capabilities;
}

/**
 * @return array{jit: bool, notes: list<string>}
 */
function analyzeInternal(PHPCompiler\Func\Internal $fn): array
{
    $ref = new ReflectionClass($fn);
    $file = $ref->getFileName();
    $source = $file !== false ? (string) file_get_contents($file) : '';
    $notes = [];

    if (preg_match('/\bVM only\b/i', $source)) {
        $notes[] = 'doc: VM only';
    }
    if ('array_map' === $fn->getName() && str_contains($source, 'VmClosureCall::isClosure')) {
        $notes[] = 'callbacks: null/string builtins JIT/AOT; VM closure callbacks (#3086, #1154)';
    } elseif ('array_map' === $fn->getName() && preg_match('/callables are deferred/i', $source)) {
        $notes[] = 'callbacks: null/string builtins; closures deferred (#1154)';
    }
    if ('usort' === $fn->getName() && str_contains($source, 'VmClosureCall::isClosure')) {
        $notes[] = 'callbacks: strcmp JIT; strcasecmp VM; VM closure comparator (#3086, #1210)';
    } elseif ('usort' === $fn->getName() && preg_match('/callables are deferred/i', $source)) {
        $notes[] = 'callbacks: strcmp JIT; strcasecmp VM; closures deferred (#1210)';
    }
    if ('uksort' === $fn->getName() && str_contains($source, 'VmClosureCall::isClosure')) {
        $notes[] = 'callbacks: strcmp JIT (ksort lowering); strcasecmp VM; VM closure comparator (#3086, #3143)';
    } elseif ('uksort' === $fn->getName() && preg_match('/callables are deferred/i', $source)) {
        $notes[] = 'callbacks: strcmp JIT; strcasecmp VM; closures deferred (#3143)';
    }
    if ('array_filter' === $fn->getName() && str_contains($source, 'VmClosureCall::isClosure')) {
        $notes[] = 'callbacks: string builtins; VM closure callbacks (#3086)';
    }
    if ('array_walk_recursive' === $fn->getName() && str_contains($source, 'VmClosureCall::isClosure')) {
        $notes[] = 'callbacks: VM closure + string builtins; JIT/AOT recursive walk deferred (#3111)';
    }
    if ('array_reduce' === $fn->getName() && preg_match('/callables are deferred/i', $source)) {
        $notes[] = 'callbacks: string user functions VM-only; closures deferred (#1213, #142)';
    }
    if ('preg_replace_callback' === $fn->getName() && preg_match('/closures deferred/i', $source)) {
        $notes[] = 'compile-time string user-function callbacks; closures deferred (#1177, #142)';
    }
    if (\PHPCompiler\JIT\SelfHostBuiltinPolicy::isVmOnlyDeferred($fn->getName())) {
        $deferLib = __DIR__.'/stdlib-jit-deferred-lib.php';
        if (is_readable($deferLib)) {
            require_once $deferLib;
            $issue = stdlib_jit_deferred_issue_for($fn->getName());
            if (null !== $issue) {
                $notes[] = 'compile-time JIT deferred (#'.$issue.')';
            }
        }
    }

    $jit = false;
    if ($ref->hasMethod('call')) {
        $call = $ref->getMethod('call');
        if ($call->getDeclaringClass()->getName() === $ref->getName()) {
            $body = extractMethodBody($file, $call);
            $jit = !preg_match('/not implemented for JIT/i', $body);
            if (!$jit && preg_match('/not implemented for JIT[^\'"]*([^\']+)/i', $body, $m)) {
                $notes[] = trim($m[0]);
            }
        }
    }

    return ['jit' => $jit, 'notes' => $notes];
}

function extractMethodBody(string $file, ReflectionMethod $method): string
{
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return '';
    }
    $start = $method->getStartLine() - 1;
    $end = $method->getEndLine() - 1;

    return implode("\n", array_slice($lines, $start, $end - $start + 1));
}

/** @return array<string, list<string>> */
function collectPhptCoverage(string $root): array
{
    $jit = [];
    $aot = [];

    $jitDirs = [
        $root . '/test/compliance/cases',
    ];
    foreach ($jitDirs as $dir) {
        if (!is_dir($dir)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
        foreach ($it as $file) {
            if (!$file->isFile() || !str_ends_with($file->getFilename(), '.phpt')) {
                continue;
            }
            if (!str_contains($file->getFilename(), 'jit')) {
                continue;
            }
            tagPhptFunctions($file->getPathname(), $jit, 'JIT PHPT');
        }
    }

    $aotDir = $root . '/test/fixtures/aot/cases';
    if (is_dir($aotDir)) {
        foreach (glob($aotDir . '/*.phpt') ?: [] as $phpt) {
            tagPhptFunctions($phpt, $aot, 'AOT PHPT');
        }
    }

    return ['jit' => $jit, 'aot' => $aot];
}

/** @param array<string, list<string>> $bucket */
function tagPhptFunctions(string $phpt, array &$bucket, string $tag): void
{
    $content = (string) file_get_contents($phpt);
    if (preg_match('/--TEST--\s*\n(.+)/', $content, $m)) {
        $title = strtolower(trim($m[1]));
        if (preg_match('/\b([a-z_][a-z0-9_]*)\(\)/', $title, $fn)) {
            $bucket[$fn[1]] ??= [];
            if (!in_array($tag, $bucket[$fn[1]], true)) {
                $bucket[$fn[1]][] = $tag;
            }
        }
    }
    if (preg_match('/--FILE--\s*\n<\?php(.*?)(?:--EXPECT|$)/s', $content, $m)) {
        if (preg_match_all('/\b([a-z_][a-z0-9_]*)\s*\(/', $m[1], $calls)) {
            foreach (array_unique($calls[1]) as $fn) {
                if (in_array($fn, ['echo', 'print', 'var_dump', 'putenv', 'define'], true)) {
                    continue;
                }
                $bucket[$fn] ??= [];
                if (!in_array($tag, $bucket[$fn], true)) {
                    $bucket[$fn][] = $tag;
                }
            }
        }
    }
}

/**
 * @param array<string, array{vm: bool, jit: bool, aot: bool|string, notes: list<string>, module: string}> $capabilities
 */
function renderBuiltinMarkdown(array $capabilities, array $phpt): string
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

    foreach ($capabilities as $name => $row) {
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
    $lines[] = '## Language constructs';
    $lines[] = '';
    $lines[] = 'See [capabilities-syntax.md](capabilities-syntax.md) (generated by `script/capability-syntax.php`):';
    $lines[] = 'classes, methods, visibility, `instanceof`, native user-class link (#568 closed; execute ✅ #764 closed), `match`, arrow functions.';
    $lines[] = '';
    $lines[] = '_Builtin AOT uses the same LLVM path as JIT unless noted otherwise._';
    $lines[] = '';

    return implode("\n", $lines);
}

$capabilities = applyBuiltinCapabilityCurations(collectCapabilities($root));
$phpt = collectPhptCoverage($root);
$markdown = renderBuiltinMarkdown($capabilities, $phpt);

if ($check) {
    if (!is_file($outFile)) {
        fwrite(STDERR, "Missing $outFile — run: php script/capability-matrix.php\n");
        exit(1);
    }
    $committed = (string) file_get_contents($outFile);
    if ($committed !== $markdown) {
        fwrite(STDERR, "docs/capabilities.md is out of date — run: php script/capability-matrix.php\n");
        exit(1);
    }
    fwrite(STDOUT, 'docs/capabilities.md is up to date (' . count($capabilities) . " builtins).\n");
    exit(0);
}

if (!is_dir(dirname($outFile))) {
    mkdir(dirname($outFile), 0755, true);
}
file_put_contents($outFile, $markdown);
fwrite(STDOUT, "Wrote $outFile (" . count($capabilities) . " builtins).\n");
