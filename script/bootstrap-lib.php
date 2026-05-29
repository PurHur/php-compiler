<?php

declare(strict_types=1);

/**
 * Shared bootstrap self-host helpers (issue #212).
 */

/** Language constructs excluded from the bootstrap AOT subset until lowered. */
const BOOTSTRAP_UNSUPPORTED_CONSTRUCTS = [
    'generator yield',
    'enum',
    'eval()',
    'create_function()',
    'passthru()',
];

/**
 * The inventory scanner prefers nikic/php-parser, but some harness runs intentionally operate
 * without a full `vendor/` tree. Define the PhpParser visitor only when the classes exist.
 */
if (class_exists(\PhpParser\NodeVisitorAbstract::class)) {
    final class BootstrapConstructVisitor extends \PhpParser\NodeVisitorAbstract
    {
        /** @var list<string> */
        public array $blockers = [];

        /** @var list<string> */
        public array $warnings = [];

        private int $classMethodCount = 0;

        private int $closureCount = 0;

        public function enterNode(\PhpParser\Node $node)
        {
            if ($node instanceof \PhpParser\Node\Expr\Yield_ || $node instanceof \PhpParser\Node\Expr\YieldFrom) {
                $this->blockers[] = 'generator yield (line '.$node->getLine().')';
            } elseif ($node instanceof \PhpParser\Node\Stmt\ClassMethod && $node->name->toString() !== '__construct') {
                ++$this->classMethodCount;
            } elseif ($node instanceof \PhpParser\Node\Stmt\Enum_) {
                $this->blockers[] = 'enum (line '.$node->getLine().')';
            } elseif ($node instanceof \PhpParser\Node\Stmt\Trait_) {
                $this->warnings[] = 'trait '.$node->name.' (line '.$node->getLine().')';
            } elseif ($node instanceof \PhpParser\Node\Expr\Closure || $node instanceof \PhpParser\Node\Expr\ArrowFunction) {
                ++$this->closureCount;
            } elseif ($node instanceof \PhpParser\Node\Expr\New_ && $node->class instanceof \PhpParser\Node\Name) {
                $name = $node->class->toString();
                if (!str_starts_with($name, 'PHPCompiler\\')
                    && !str_starts_with($name, 'PHPCfg\\')
                    && !str_starts_with($name, 'PHPTypes\\')
                    && !str_starts_with($name, 'PhpParser\\')
                    && !str_starts_with($name, 'PHPLLVM\\')
                    && !in_array($name, ['LogicException', 'RuntimeException', 'InvalidArgumentException', 'TypeError', 'ValueError', 'ReflectionClass', 'SplObjectStorage'], true)
                ) {
                    $this->warnings[] = 'new '.$name.' (line '.$node->getLine().')';
                }
            } elseif ($node instanceof \PhpParser\Node\Expr\FuncCall && $node->name instanceof \PhpParser\Node\Name) {
                $fn = $node->name->toString();
                if (in_array($fn, ['eval', 'create_function', 'passthru'], true)) {
                    $this->blockers[] = $fn.'() (line '.$node->getLine().')';
                }
            }
        }

        public function beforeTraverse(array $nodes)
        {
            $this->classMethodCount = 0;
            $this->closureCount = 0;
        }

        public function afterTraverse(array $nodes)
        {
            if ($this->classMethodCount > 0) {
                $this->warnings[] = $this->classMethodCount.' class method(s) — PHPCfg Op\\Stmt\\ClassMethod not lowered in Compiler';
            }
            if ($this->closureCount > 0) {
                $this->warnings[] = $this->closureCount.' closure(s)';
            }
        }
    }
}

/**
 * @return list<string>
 */
function bootstrapExtractCompilerBlockers(string $compilerFile): array
{
    $source = (string) file_get_contents($compilerFile);
    $blockers = [];
    if (preg_match_all('/throw new \\\\LogicException\([\'"]([^\'"]+)[\'"]/', $source, $m)) {
        foreach ($m[1] as $msg) {
            if (stripos($msg, 'unknown') !== false || stripos($msg, 'unsupported') !== false) {
                $blockers[] = $msg;
            }
        }
    }

    return array_values(array_unique($blockers));
}

/**
 * @return array{blockers: list<string>, warnings: list<string>}
 */
function bootstrapScanConstructs(string $file): array
{
    $code = (string) file_get_contents($file);
    if (class_exists(\PhpParser\ParserFactory::class) && class_exists(BootstrapConstructVisitor::class)) {
        $parser = (new \PhpParser\ParserFactory())->create(\PhpParser\ParserFactory::PREFER_PHP7);
        try {
            $ast = $parser->parse($code);
        } catch (Throwable $e) {
            return [
                'blockers' => ['parse error: '.$e->getMessage()],
                'warnings' => [],
            ];
        }
        if (!is_array($ast)) {
            return ['blockers' => [], 'warnings' => []];
        }

        $visitor = new BootstrapConstructVisitor();
        $traverser = new \PhpParser\NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        return [
            'blockers' => $visitor->blockers,
            'warnings' => $visitor->warnings,
        ];
    }

    return bootstrapScanConstructsToken($code);
}

/**
 * Token-based fallback for harness runs without nikic/php-parser.
 *
 * @return array{blockers: list<string>, warnings: list<string>}
 */
function bootstrapScanConstructsToken(string $code): array
{
    $tokens = token_get_all($code);
    $blockers = [];
    $warnings = [];

    $classMethodCount = 0;
    $closureCount = 0;
    $inClass = 0;

    $count = count($tokens);
    for ($i = 0; $i < $count; ++$i) {
        $t = $tokens[$i];

        if (!is_array($t)) {
            if ('{' === $t) {
                if ($inClass > 0) {
                    ++$inClass;
                }
            } elseif ('}' === $t) {
                if ($inClass > 0) {
                    --$inClass;
                }
            }
            continue;
        }

        [$id, $text, $line] = $t;

        if (T_YIELD === $id || (defined('T_YIELD_FROM') && T_YIELD_FROM === $id)) {
            $blockers[] = 'generator yield (line '.$line.')';
            continue;
        }

        if (defined('T_ENUM') && T_ENUM === $id) {
            $blockers[] = 'enum (line '.$line.')';
            continue;
        }

        if (T_TRAIT === $id) {
            $warnings[] = 'trait (line '.$line.')';
            continue;
        }

        if (T_CLASS === $id || (defined('T_INTERFACE') && T_INTERFACE === $id)) {
            $inClass = max(1, $inClass);
            continue;
        }

        if (T_FUNCTION === $id) {
            $j = $i + 1;
            while ($j < $count) {
                $n = $tokens[$j];
                if (is_array($n) && in_array($n[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    ++$j;
                    continue;
                }
                if ('&' === $n) {
                    ++$j;
                    continue;
                }
                if ('(' === $n) {
                    ++$closureCount;
                } elseif (is_array($n) && T_STRING === $n[0]) {
                    $name = $n[1];
                    if ($inClass > 0 && '__construct' !== strtolower($name)) {
                        ++$classMethodCount;
                    }
                }
                break;
            }
            continue;
        }

        if (defined('T_FN') && T_FN === $id) {
            ++$closureCount;
            continue;
        }

        if (T_STRING === $id) {
            $lower = strtolower($text);
            if (in_array($lower, ['eval', 'create_function', 'passthru'], true)) {
                $j = $i + 1;
                while ($j < $count) {
                    $n = $tokens[$j];
                    if (is_array($n) && in_array($n[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                        ++$j;
                        continue;
                    }
                    if ('(' === $n) {
                        $blockers[] = $lower.'() (line '.$line.')';
                    }
                    break;
                }
            }
            continue;
        }
    }

    if ($classMethodCount > 0) {
        $warnings[] = $classMethodCount.' class method(s) — PHPCfg Op\\Stmt\\ClassMethod not lowered in Compiler';
    }
    if ($closureCount > 0) {
        $warnings[] = $closureCount.' closure(s)';
    }

    return [
        'blockers' => array_values(array_unique($blockers)),
        'warnings' => array_values(array_unique($warnings)),
    ];
}

/**
 * @return list<string>
 */
function bootstrapVmPathPhpFiles(string $root): array
{
    $entryFiles = [
        'bin/vm.php',
        'src/tokenizer-compat.php',
        'src/yay-php8-compat.php',
        // bootstrap spine uses shims to avoid unsupported lowering for entry scripts (#1467).
        'test/bootstrap-aot/cli_spine_shim.php',
        'test/bootstrap-aot/llvm_env_spine_shim.php',
        'test/bootstrap-aot/macro_functions_spine_shim.php',
    ];
    $exclude = [
        'src/cli.php',
        'src/cli_driver.php',
        'src/llvm-env.php',
        'src/macro_functions.php',
    ];

    $files = [];
    foreach ($entryFiles as $rel) {
        $path = $root.'/'.$rel;
        if (is_file($path)) {
            $files[$path] = true;
        }
    }
    foreach (['lib', 'ext', 'src'] as $dir) {
        $base = $root.'/'.$dir;
        if (!is_dir($base)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base));
        foreach ($it as $file) {
            if ($file->isFile() && str_ends_with($file->getPathname(), '.php')) {
                $rel = substr($file->getPathname(), strlen($root) + 1);
                if (in_array($rel, $exclude, true)) {
                    continue;
                }
                $files[$file->getPathname()] = true;
            }
        }
    }
    ksort($files, SORT_STRING);

    return array_keys($files);
}

/**
 * @return array<string, mixed>
 */
function bootstrapCollectInventoryReport(string $root): array
{
    require_once __DIR__.'/bootstrap-phase-a-deferred.php';

    $compilerBlockers = bootstrapExtractCompilerBlockers($root.'/lib/Compiler.php');
    $fileReports = [];
    $totals = ['files' => 0, 'blockers' => 0, 'warnings' => 0];
    foreach (bootstrapVmPathPhpFiles($root) as $path) {
        if (!is_file($path) || !str_ends_with($path, '.php')) {
            continue;
        }
        $rel = substr($path, strlen($root) + 1);
        $constructs = bootstrapScanConstructs($path);
        $fileReports[$rel] = $constructs;
        ++$totals['files'];
        $totals['blockers'] += count($constructs['blockers']);
        $totals['warnings'] += count($constructs['warnings']);
    }

    $report = [
        'entry' => 'bin/vm.php',
        'compiler_blockers' => $compilerBlockers,
        'totals' => $totals,
        'files' => $fileReports,
    ];
    $report['phase_a'] = bootstrap_phase_a_inventory_counts($report);
    $report['phase_a']['ratio_deferred_paths'] = bootstrap_phase_a_ratio_deferred();

    return $report;
}

/**
 * Procedural scripts used as AOT lint gates for the bootstrap subset (Phase B).
 *
 * @return list<string> repo-relative paths
 */
function bootstrapDefaultAotLintTargets(string $root): array
{
    $targets = [
        'examples/000-HelloWorld/example.php',
    ];
    foreach (glob($root.'/test/bootstrap-aot/*.php') ?: [] as $path) {
        $targets[] = substr($path, strlen($root) + 1);
    }
    // Multi-file chains: test/bootstrap-aot/<name>/main.php (issue #120).
    foreach (glob($root.'/test/bootstrap-aot/*/main.php') ?: [] as $path) {
        $targets[] = substr($path, strlen($root) + 1);
    }
    sort($targets, SORT_STRING);

    return array_values(array_unique($targets));
}

/**
 * Phase C link+execute targets (issue #512). Subset of lint targets that fully AOT-compile today.
 *
 * @param list<string> $lintTargets
 *
 * @return list<string>
 */
function bootstrapDefaultAotLinkTargets(array $lintTargets): array
{
    $pendingUserFunc = [
        'test/bootstrap-aot/lib_opcode/main.php', // Phase D: aot_link_lib_targets only (#540)
        'test/bootstrap-aot/spl_object_storage_dim.php', // lint OK; LLVM verify on link (#601)
        'test/bootstrap-aot/class_int_property.php', // lint OK; LLVM verify on link
        'test/bootstrap-aot/external_cfg_block_children.php', // lint OK; LLVM verify on link
        'test/bootstrap-aot/block_orig_children_foreach.php', // lint OK; LLVM verify on link (#848)
        'test/bootstrap-aot/isset_object_typed_property.php', // lint OK; LLVM verify on link (#764)
        'test/bootstrap-aot/const_string_folder_deploy_path.php', // lint OK; vendor autoload inline on link (#816)
        'test/bootstrap-aot/deploy_path_fold.php', // lint OK; DeployRoot bundle verify on link (#816)
        'test/bootstrap-aot/file_get_contents_concat.php', // lint OK; LLVM verify on link (addrspacecast)
        'test/bootstrap-aot/ns_nullable_return.php', // lint OK; nullable NsFuncCall return JIT pending
        'test/bootstrap-aot/nullsafe_method_call.php', // lint OK; ?->method() native link pending
        'test/bootstrap-aot/assign_ref_alias.php', // lint OK; JIT reference alias runtime pending
        'test/bootstrap-aot/global_var_link.php', // lint OK; JIT user-global runtime pending
    ];

    return array_values(array_filter(
        $lintTargets,
        static fn (string $rel): bool => !in_array($rel, $pendingUserFunc, true),
    ));
}

/**
 * Phase D link+execute targets: first namespaced lib/ translation unit (issue #540).
 *
 * @return list<string> repo-relative paths
 */
function bootstrapDefaultAotLinkLibTargets(): array
{
    return [
        'test/bootstrap-aot/lib_opcode/main.php',
    ];
}

/**
 * @param array<string, mixed> $inventory
 *
 * @return array<string, mixed>
 */
function bootstrapBuildProfile(array $inventory, string $root): array
{
    $excluded = [];
    $eligible = [];
    foreach ($inventory['files'] as $rel => $info) {
        if (count($info['blockers']) > 0) {
            $excluded[] = $rel;
        } else {
            $eligible[] = $rel;
        }
    }
    sort($excluded, SORT_STRING);
    sort($eligible, SORT_STRING);

    $lintTargets = bootstrapDefaultAotLintTargets($root);
    $linkTargets = bootstrapDefaultAotLinkTargets($lintTargets);
    $linkLibTargets = bootstrapDefaultAotLinkLibTargets();
    foreach ($lintTargets as $rel) {
        if (!is_file($root.'/'.$rel)) {
            throw new RuntimeException("bootstrap profile lint target missing: {$rel}");
        }
        if (isset($inventory['files'][$rel]) && count($inventory['files'][$rel]['blockers']) > 0) {
            throw new RuntimeException("bootstrap profile lint target has inventory blockers: {$rel}");
        }
    }

    return [
        'phase' => 'B',
        'issue' => 212,
        'entry' => $inventory['entry'],
        'unsupported_constructs' => BOOTSTRAP_UNSUPPORTED_CONSTRUCTS,
        'compiler_cfg_gaps' => $inventory['compiler_blockers'],
        'excluded_files' => $excluded,
        'eligible_files' => $eligible,
        'aot_lint_targets' => $lintTargets,
        'aot_link_targets' => $linkTargets,
        'aot_link_lib_targets' => $linkLibTargets,
        'totals' => [
            'inventory_files' => $inventory['totals']['files'],
            'excluded' => count($excluded),
            'eligible' => count($eligible),
            'aot_lint_targets' => count($lintTargets),
            'aot_link_targets' => count($linkTargets),
            'aot_link_lib_targets' => count($linkLibTargets),
        ],
    ];
}

/**
 * @param array<string, mixed> $report
 */
function bootstrapRenderMarkdown(array $report): string
{
    require_once __DIR__.'/bootstrap-phase-a-deferred.php';

    $lines = [];
    $lines[] = '# Bootstrap inventory (vm.php path)';
    $lines[] = '';
    $lines[] = 'Auto-generated by `script/bootstrap-inventory.php`. Tracks **Phase A** of [#212](https://github.com/PurHur/php-compiler/issues/212) (self-host bootstrap).';
    $lines[] = '';
    $lines[] = 'Regenerate: `php script/bootstrap-inventory.php`';
    $lines[] = '';
    $lines[] = '## Summary';
    $lines[] = '';
    $phaseA = $report['phase_a'] ?? bootstrap_phase_a_inventory_counts($report);
    $lines[] = '| Metric | Count |';
    $lines[] = '|--------|------:|';
    $lines[] = '| PHP files on vm.php path | '.$report['totals']['files'].' |';
    $lines[] = '| Phase A inventory files (M2 ratio SSOT) | '.$phaseA['phase_a_inventory_files'].' |';
    $lines[] = '| Phase A ratio-deferred paths | '.$phaseA['phase_a_ratio_deferred'].' |';
    $lines[] = '| Source constructs flagged (blockers) | '.$report['totals']['blockers'].' |';
    $lines[] = '| Source constructs flagged (warnings) | '.$report['totals']['warnings'].' |';
    $lines[] = '';
    if (($phaseA['phase_a_ratio_deferred'] ?? 0) > 0) {
        $lines[] = 'Phase A ratio-deferred (still inventoried; excluded from M2 spine ratio denominator only — [#2543](https://github.com/PurHur/php-compiler/issues/2543)):';
        $lines[] = '';
        foreach ($report['phase_a']['ratio_deferred_paths'] ?? bootstrap_phase_a_ratio_deferred() as $path => $note) {
            $lines[] = '- `'.$path.'` — '.$note;
        }
        $lines[] = '';
        $lines[] = 'Included on Phase A path and in `compiler_lib_spine_smoke` (not ratio-deferred): `lib/JIT/Builtin/StringPregMatch.php`, `lib/AOT/Linker.php` (external `clang` / `shell_exec` native floor).';
        $lines[] = '';
    }
    $lines[] = '## Compiler CFG gaps (`lib/Compiler.php`)';
    $lines[] = '';
    $lines[] = 'These `LogicException` messages indicate CFG ops or expressions not yet lowered:';
    $lines[] = '';
    foreach ($report['compiler_blockers'] as $msg) {
        $lines[] = '- `'.$msg.'`';
    }
    $lines[] = '';
    $lines[] = 'Rank live CFG gaps across inventory files: `php script/bootstrap-inventory-triage.php` ([#2254](https://github.com/PurHur/php-compiler/issues/2254); `phpc doctor --selfhost` prints top 3).';
    $lines[] = '';
    $lines[] = '## Files';
    $lines[] = '';
    $lines[] = '| File | Blockers | Warnings |';
    $lines[] = '|------|----------|----------|';
    foreach ($report['files'] as $rel => $info) {
        $b = count($info['blockers']);
        $w = count($info['warnings']);
        if ($b === 0 && $w === 0) {
            continue;
        }
        $lines[] = '| `'.$rel.'` | '.$b.' | '.$w.' |';
    }
    $lines[] = '';
    $lines[] = '## Per-file construct flags';
    $lines[] = '';
    foreach ($report['files'] as $rel => $info) {
        if ($info['blockers'] === [] && $info['warnings'] === []) {
            continue;
        }
        $lines[] = '### `'.$rel.'`';
        $lines[] = '';
        if ($info['blockers'] !== []) {
            $lines[] = '**Blockers** (likely prevent AOT bootstrap compile):';
            foreach ($info['blockers'] as $item) {
                $lines[] = '- '.$item;
            }
            $lines[] = '';
        }
        if ($info['warnings'] !== []) {
            $lines[] = '**Warnings** (review for bootstrap subset):';
            foreach ($info['warnings'] as $item) {
                $lines[] = '- '.$item;
            }
            $lines[] = '';
        }
    }

    return implode("\n", $lines)."\n";
}

/**
 * Sidecar for live self-host compile probe (issue #2891) — not part of inventory --check SSOT.
 */
function bootstrapInventoryLiveProbeFile(string $root): string
{
    return $root.'/docs/bootstrap-inventory-live-probe.md';
}

function bootstrapWriteInventoryLiveProbe(string $root, string $message): void
{
    $file = bootstrapInventoryLiveProbeFile($root);
    $body = "# Live self-host compile probe\n\n"
        ."Auto-updated by `bootstrap-selfhost-compile-probe.php --update-inventory`.\n"
        ."Not compared by `php script/bootstrap-inventory.php --check` ([#2891](https://github.com/PurHur/php-compiler/issues/2891)).\n\n"
        .'- `'.$message."`\n";
    file_put_contents($file, $body);
}

/**
 * Strip legacy probe section from committed docs/bootstrap-inventory.md (pre-#2891).
 */
/**
 * Ignore line-number-only drift in per-file warning rows (#3048).
 */
function bootstrapNormalizeInventoryLineNumbers(string $content): string
{
    return preg_replace('/ \(line \d+\)/', ' (line ?)', $content) ?? $content;
}

function bootstrapStripInventoryProbeSection(string $content): string
{
    $needle = "\n## Live self-host compile probe\n";
    $pos = strpos($content, $needle);
    if (false === $pos) {
        return $content;
    }
    $after = substr($content, $pos + strlen($needle));
    if (!preg_match('/\n## /', $after, $m, PREG_OFFSET_CAPTURE)) {
        return substr($content, 0, $pos)."\n";
    }
    $end = $pos + strlen($needle) + $m[0][1];
    $stripped = substr($content, 0, $pos).substr($content, $end);

    return preg_replace('/\n\n\n(## Files)/', "\n\n$1", $stripped, 1) ?? $stripped;
}

/**
 * @param array<string, mixed> $profile
 */
function bootstrapProfileJson(array $profile): string
{
    return json_encode($profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
}

/**
 * Resolve LLVM 9 directory for bootstrap AOT lint (mirrors script/jit-runtime-probe.php).
 */
function bootstrapResolveLlvmDir(string $root): ?string
{
    $llvmDir = getenv('PHP_COMPILER_LLVM_PATH') ?: '';
    if ('' !== $llvmDir && is_file($llvmDir.'/libLLVM-9.so.1')) {
        return realpath($llvmDir) ?: $llvmDir;
    }
    foreach ([$root.'/.llvm', '/opt/llvm9'] as $candidate) {
        if (is_file($candidate.'/libLLVM-9.so.1')) {
            return realpath($candidate) ?: $candidate;
        }
    }

    return null;
}

/**
 * @return array<string, string>
 */
function bootstrapLlvmProcessEnv(string $llvmDir): array
{
    $env = [];
    foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
        if (is_string($value)) {
            $env[$key] = $value;
        }
    }
    $env['PHP_COMPILER_LLVM_PATH'] = $llvmDir;
    $ld = $env['LD_LIBRARY_PATH'] ?? '';
    $env['LD_LIBRARY_PATH'] = '' === $ld ? $llvmDir : $llvmDir.':'.$ld;
    $path = $env['PATH'] ?? '';
    $env['PATH'] = '' === $path ? $llvmDir : $llvmDir.':'.$path;

    return $env;
}

/**
 * First fatal / LogicException line from compile probe output (skips Notice/Deprecated).
 */
function bootstrapSelfhostProbeLastJitFunc(?string $progressFile = null): ?string
{
    $path = $progressFile ?? getenv('PHP_COMPILER_JIT_PROGRESS_FILE');
    if (false === $path || '' === $path) {
        return null;
    }

    return \PHPCompiler\JIT\Progress::readLast($path);
}

function bootstrapSelfhostProbeExtractNextLower(string $output): ?string
{
    foreach (preg_split('/\R/', $output) as $line) {
        $line = trim($line);
        if ('' === $line) {
            continue;
        }
        if (preg_match('/\b(?:PHP )?(?:Notice|Deprecated):\s/i', $line)) {
            continue;
        }
        if (preg_match('/\b(?:PHP )?Parse error:\s*(.+)$/i', $line, $m)) {
            return trim($m[1]);
        }
        if (preg_match('/\bUncaught \S+:\s*(.+)$/i', $line, $m)) {
            return trim($m[1]);
        }
        if (preg_match('/\bLogicException:\s*(.+)$/i', $line, $m)) {
            return trim($m[1]);
        }
        if (preg_match('/\b(?:PHP )?Fatal error:\s*(.+)$/i', $line, $m)) {
            return trim($m[1]);
        }
    }

    return null;
}
