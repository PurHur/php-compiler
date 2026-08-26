<?php

declare(strict_types=1);

/**
 * Self-host AOT stub audit helpers — compile spine `parseAndCompile → standalone → Compiler::compile` (#8720).
 *
 * Static analysis of lib/JIT.php (no LLVM required) for CI trend reporting.
 */

/**
 * @return list<string>
 */
function selfhost_aot_stub_compiler_skip_patterns_from_jit(string $jitPath): array
{
    if (!is_readable($jitPath)) {
        return [];
    }
    $source = (string) file_get_contents($jitPath);
    if (!preg_match(
        '/function isSkippedCompilerHotPathName\(string \$name\): bool\s*\{.*?return (.*?);\s*\n    \}/s',
        $source,
        $matches
    )) {
        return [];
    }
    preg_match_all("/str_contains\\(\\\$lower, '([^']+)'\\)/", $matches[1], $found);

    return array_values(array_unique($found[1]));
}

/**
 * @return list<string> method suffixes after \\compiler::
 */
function selfhost_aot_stub_m3_compiler_php_lowering_suffixes_from_jit(string $jitPath): array
{
    return selfhost_aot_stub_parse_compiler_suffix_list(
        $jitPath,
        'm3CompileDriverCompilerPhpLoweringSuffixes'
    );
}

/**
 * @return list<string> method suffixes after \\compiler::
 */
function selfhost_aot_stub_m3_compiler_native_lowering_suffixes_from_jit(string $jitPath): array
{
    return selfhost_aot_stub_parse_compiler_suffix_list(
        $jitPath,
        'm3CompileDriverCompilerNativeLoweringSuffixes'
    );
}

/**
 * @return list<string>
 */
function selfhost_aot_stub_parse_compiler_suffix_list(string $jitPath, string $method): array
{
    if (!is_readable($jitPath)) {
        return [];
    }
    $source = (string) file_get_contents($jitPath);
    if (!preg_match(
        '/private function '.$method.'\(\): array\s*\{.*?return \[(.*?)\];\s*\}/s',
        $source,
        $matches
    )) {
        return [];
    }
    preg_match_all("/'((?:\\\\.|[^'\\\\])*)'/", $matches[1], $found);
    $suffixes = [];
    foreach ($found[1] as $raw) {
        $suffixes[] = stripcslashes($raw);
    }

    return array_values(array_unique($suffixes));
}

/**
 * Compile-spine symbols probed under PHP_COMPILER_SELFHOST_AOT=1 + PHP_COMPILER_M3_COMPILE_DRIVER=1.
 *
 * @return list<array{symbol: string, cluster: string}>
 */
function selfhost_aot_stub_compile_spine_symbols(): array
{
    $runtime = [
        'PHPCompiler\\Runtime::__construct',
        'PHPCompiler\\Runtime::parseAndCompile',
        'PHPCompiler\\Runtime::parseAndCompileEmitSmoke',
        'PHPCompiler\\Runtime::parse',
        'PHPCompiler\\Runtime::compile',
        'PHPCompiler\\Runtime::compileEmitSmoke',
        'PHPCompiler\\Runtime::standalone',
        'PHPCompiler\\Runtime::loadJit',
        'PHPCompiler\\Runtime::loadJitContext',
        'PHPCompiler\\Runtime::createJit',
        'PHPCompiler\\Runtime::jitContextForLoadJit',
        'PHPCompiler\\Runtime::loadJitCompileModuleFuncs',
        'PHPCompiler\\Runtime::initVmContext',
        'PHPCompiler\\Runtime::initCompiler',
        'PHPCompiler\\Runtime::initParsePipeline',
        'PHPCompiler\\Runtime::loadCoreModules',
        'PHPCompiler\\Runtime::__destruct',
        'PHPCompiler\\Runtime::noteParseCompileNullForScript',
        'PHPCompiler\\Runtime::peekLastParseFailure',
        'PHPCompiler\\Block::slotIndexForVariableName',
        'PHPCompiler\\Block::slotForOperand',
    ];
    $compiler = [
        'PHPCompiler\\Compiler::compile',
        'PHPCompiler\\Compiler::compileFunc',
        'PHPCompiler\\Compiler::compileBlock',
        'PHPCompiler\\Compiler::compileExpr',
        'PHPCompiler\\Compiler::compileCfgBranch',
        'PHPCompiler\\Compiler::compileOp',
        'PHPCompiler\\Compiler::compileStmt',
        'PHPCompiler\\Compiler::operandsChainEqual',
        'PHPCompiler\\Compiler::unwrapOperandChain',
    ];

    // BootstrapAot\* probe drivers are not on parseAndCompile → standalone → Compiler::compile.
    // Counting them as spine stubs (#35009) hid that the real spine is fully M3-real-lowered.

    $rows = [];
    foreach ($runtime as $symbol) {
        $rows[] = ['symbol' => $symbol, 'cluster' => 'runtime_spine'];
    }
    foreach ($compiler as $symbol) {
        $rows[] = ['symbol' => $symbol, 'cluster' => 'compiler_spine'];
    }

    return $rows;
}

/**
 * @param array{
 *   m3_allow: list<string>,
 *   m3_deny: list<string>,
 *   compiler_skip_patterns: list<string>,
 *   compiler_php_lowering: list<string>,
 *   compiler_native_lowering: list<string>
 * } $ctx
 *
 * @return 'm3_real'|'m3_deny'|'entry_stub'|'compiler_stub'|'other'
 */
function selfhost_aot_stub_classify_symbol_static(string $symbol, array $ctx): string
{
    $lower = strtolower($symbol);

    foreach ($ctx['m3_deny'] as $fragment) {
        $frag = strtolower(ltrim($fragment, '\\'));
        if (str_contains($lower, $frag)) {
            return 'm3_deny';
        }
    }

    foreach ($ctx['m3_allow'] as $suffix) {
        if (str_ends_with($lower, strtolower($suffix))) {
            return 'm3_real';
        }
    }

    foreach ($ctx['compiler_php_lowering'] as $suffix) {
        if (str_ends_with($lower, '\\compiler::'.strtolower($suffix))) {
            return 'm3_real';
        }
    }
    foreach ($ctx['compiler_native_lowering'] as $suffix) {
        if (str_ends_with($lower, '\\compiler::'.strtolower($suffix))) {
            return 'm3_real';
        }
    }

    if (str_ends_with($lower, '\\compiler::compilefunc')
        || str_ends_with($lower, '\\compiler::compile')
    ) {
        return 'entry_stub';
    }

    if (str_contains($lower, '\\runtime::')
        || str_contains($lower, '\\func\\php::')
        || str_contains($lower, '\\func::')
        || str_contains($lower, '\\frame::')
        || str_contains($lower, '\\block::')
    ) {
        return 'entry_stub';
    }

    if (str_contains($lower, '\\compiler::')) {
        foreach ($ctx['compiler_skip_patterns'] as $pattern) {
            if (str_contains($lower, $pattern)) {
                return 'compiler_stub';
            }
        }

        return 'compiler_stub';
    }

    return 'other';
}

/**
 * @return array{
 *   compiler_skip_patterns: int,
 *   m3_allow: int,
 *   m3_deny: int,
 *   spine: array<string, int>,
 *   spine_symbols: list<array{symbol: string, cluster: string, status: string}>
 * }
 */
function selfhost_aot_stub_collect_metrics(string $root): array
{
    require_once __DIR__.'/bootstrap-m3-allowlist.php';

    $jitPath = $root.'/lib/JIT.php';
    $patterns = selfhost_aot_stub_compiler_skip_patterns_from_jit($jitPath);
    $m3 = bootstrap_m3_allowlist_from_jit($jitPath);
    $ctx = [
        'm3_allow' => $m3['allow'],
        'm3_deny' => $m3['deny'],
        'compiler_skip_patterns' => $patterns,
        'compiler_php_lowering' => selfhost_aot_stub_m3_compiler_php_lowering_suffixes_from_jit($jitPath),
        'compiler_native_lowering' => selfhost_aot_stub_m3_compiler_native_lowering_suffixes_from_jit($jitPath),
    ];

    $spineCounts = [
        'm3_real' => 0,
        'm3_deny' => 0,
        'entry_stub' => 0,
        'compiler_stub' => 0,
        'other' => 0,
    ];
    $spineSymbols = [];
    foreach (selfhost_aot_stub_compile_spine_symbols() as $row) {
        $status = selfhost_aot_stub_classify_symbol_static($row['symbol'], $ctx);
        ++$spineCounts[$status];
        $spineSymbols[] = [
            'symbol' => $row['symbol'],
            'cluster' => $row['cluster'],
            'status' => $status,
        ];
    }

    return [
        'compiler_skip_patterns' => count($patterns),
        'm3_allow' => count($m3['allow']),
        'm3_deny' => count($m3['deny']),
        'spine' => $spineCounts,
        'spine_symbols' => $spineSymbols,
    ];
}

/**
 * @param array{
 *   compiler_skip_patterns: int,
 *   m3_allow: int,
 *   m3_deny: int,
 *   spine: array<string, int>
 * } $metrics
 *
 * @return array<string, mixed>
 */
function selfhost_aot_stub_snapshot_payload(array $metrics): array
{
    return [
        'compiler_skip_patterns' => $metrics['compiler_skip_patterns'],
        'm3_allow' => $metrics['m3_allow'],
        'm3_deny' => $metrics['m3_deny'],
        'spine' => $metrics['spine'],
    ];
}

/**
 * @param array{
 *   compiler_skip_patterns: int,
 *   m3_allow: int,
 *   m3_deny: int,
 *   spine: array<string, int>,
 *   spine_symbols: list<array{symbol: string, cluster: string, status: string}>
 * } $metrics
 */
function selfhost_aot_stub_render_markdown(array $metrics): string
{
    $spine = $metrics['spine'];
    $stubbed = $spine['entry_stub'] + $spine['compiler_stub'] + $spine['m3_deny'];
    $real = $spine['m3_real'] + $spine['other'];

    $md = "# Self-host AOT stub audit (compile spine)\n\n";
    $md .= "Auto-generated by `script/audit-selfhost-aot-stubs.php`. Regenerate: `php script/audit-selfhost-aot-stubs.php`\n\n";
    $md .= "Path: `parseAndCompile` → `standalone` → `Compiler::compile` under `PHP_COMPILER_SELFHOST_AOT=1` + `PHP_COMPILER_M3_COMPILE_DRIVER=1`.\n\n";
    $md .= "| Metric | Count |\n|--------|------:|\n";
    $md .= '| Compiler hot-path skip patterns (`isSkippedCompilerHotPathName`) | '.$metrics['compiler_skip_patterns']." |\n";
    $md .= '| M3 real-lowering allowlist (`isM3CompileDriverRealLoweringName`) | '.$metrics['m3_allow']." |\n";
    $md .= '| M3 spine deny fragments (`m3CompileDriverSpineDenyNames`) | '.$metrics['m3_deny']." |\n";
    $md .= '| Compile-spine symbols — M3 real lowering | '.$spine['m3_real']." |\n";
    $md .= '| Compile-spine symbols — entry stub | '.$spine['entry_stub']." |\n";
    $md .= '| Compile-spine symbols — compiler hot-path stub | '.$spine['compiler_stub']." |\n";
    $md .= '| Compile-spine symbols — M3 deny | '.$spine['m3_deny']." |\n";
    $md .= '| Compile-spine symbols — other (VM/native) | '.$spine['other']." |\n";
    $md .= '| Compile-spine symbols — stubbed total | '.$stubbed." |\n";
    $md .= '| Compile-spine symbols — honest total | '.$real." |\n\n";

    $byStatus = [];
    foreach ($metrics['spine_symbols'] as $row) {
        $byStatus[$row['status']][] = $row;
    }
    $statusOrder = ['m3_real', 'entry_stub', 'compiler_stub', 'm3_deny', 'other'];
    $statusTitles = [
        'm3_real' => 'M3 real lowering (compile spine)',
        'entry_stub' => 'Self-host entry stubs (`isSkippedSelfHostEntryName`)',
        'compiler_stub' => 'Compiler hot-path stubs (`isSkippedCompilerHotPathName`)',
        'm3_deny' => 'M3 spine deny (LLVM 9 crashers)',
        'other' => 'Other (not stubbed on spine probe)',
    ];
    foreach ($statusOrder as $status) {
        $rows = $byStatus[$status] ?? [];
        $md .= '## '.$statusTitles[$status]."\n\n";
        if ([] === $rows) {
            $md .= "_None._\n\n";
            continue;
        }
        foreach ($rows as $row) {
            $md .= '- `'.$row['symbol'].'` ('.$row['cluster'].")\n";
        }
        $md .= "\n";
    }

    return $md;
}
