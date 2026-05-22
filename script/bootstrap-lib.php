<?php

declare(strict_types=1);

/**
 * Shared bootstrap self-host helpers (issue #212).
 */

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;

/** Language constructs excluded from the bootstrap AOT subset until lowered. */
const BOOTSTRAP_UNSUPPORTED_CONSTRUCTS = [
    'try/catch',
    'generator yield',
    'enum',
    'eval()',
    'create_function()',
    'shell_exec()',
    'exec()',
    'passthru()',
];

final class BootstrapConstructVisitor extends NodeVisitorAbstract
{
    /** @var list<string> */
    public array $blockers = [];

    /** @var list<string> */
    public array $warnings = [];

    private int $classMethodCount = 0;

    private int $closureCount = 0;

    public function enterNode(Node $node)
    {
        if ($node instanceof Node\Stmt\Try_) {
            $this->blockers[] = 'try/catch (line '.$node->getLine().')';
        } elseif ($node instanceof Node\Expr\Yield_ || $node instanceof Node\Expr\YieldFrom) {
            $this->blockers[] = 'generator yield (line '.$node->getLine().')';
        } elseif ($node instanceof Node\Stmt\ClassMethod && $node->name->toString() !== '__construct') {
            ++$this->classMethodCount;
        } elseif ($node instanceof Node\Stmt\Enum_) {
            $this->blockers[] = 'enum (line '.$node->getLine().')';
        } elseif ($node instanceof Node\Stmt\Trait_) {
            $this->warnings[] = 'trait '.$node->name.' (line '.$node->getLine().')';
        } elseif ($node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction) {
            ++$this->closureCount;
        } elseif ($node instanceof Node\Expr\New_ && $node->class instanceof Node\Name) {
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
        } elseif ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name) {
            $fn = $node->name->toString();
            if (in_array($fn, ['eval', 'create_function', 'shell_exec', 'exec', 'passthru'], true)) {
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
    $parser = (new ParserFactory())->create(ParserFactory::PREFER_PHP7);
    $code = (string) file_get_contents($file);
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
    $traverser = new NodeTraverser();
    $traverser->addVisitor($visitor);
    $traverser->traverse($ast);

    return [
        'blockers' => $visitor->blockers,
        'warnings' => $visitor->warnings,
    ];
}

/**
 * @return list<string>
 */
function bootstrapVmPathPhpFiles(string $root): array
{
    $entryFiles = [
        'bin/vm.php',
        'src/cli.php',
        'src/tokenizer-compat.php',
        'src/yay-php8-compat.php',
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

    return [
        'entry' => 'bin/vm.php',
        'compiler_blockers' => $compilerBlockers,
        'totals' => $totals,
        'files' => $fileReports,
    ];
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
        'test/bootstrap-aot/nullable_types.php',
    ];

    return array_values(array_filter(
        $lintTargets,
        static fn (string $rel): bool => !in_array($rel, $pendingUserFunc, true),
    ));
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
        'totals' => [
            'inventory_files' => $inventory['totals']['files'],
            'excluded' => count($excluded),
            'eligible' => count($eligible),
            'aot_lint_targets' => count($lintTargets),
            'aot_link_targets' => count($linkTargets),
        ],
    ];
}

/**
 * @param array<string, mixed> $report
 */
function bootstrapRenderMarkdown(array $report): string
{
    $lines = [];
    $lines[] = '# Bootstrap inventory (vm.php path)';
    $lines[] = '';
    $lines[] = 'Auto-generated by `script/bootstrap-inventory.php`. Tracks **Phase A** of [#212](https://github.com/PurHur/php-compiler/issues/212) (self-host bootstrap).';
    $lines[] = '';
    $lines[] = 'Regenerate: `php script/bootstrap-inventory.php`';
    $lines[] = '';
    $lines[] = '## Summary';
    $lines[] = '';
    $lines[] = '| Metric | Count |';
    $lines[] = '|--------|------:|';
    $lines[] = '| PHP files on vm.php path | '.$report['totals']['files'].' |';
    $lines[] = '| Source constructs flagged (blockers) | '.$report['totals']['blockers'].' |';
    $lines[] = '| Source constructs flagged (warnings) | '.$report['totals']['warnings'].' |';
    $lines[] = '';
    $lines[] = '## Compiler CFG gaps (`lib/Compiler.php`)';
    $lines[] = '';
    $lines[] = 'These `LogicException` messages indicate CFG ops or expressions not yet lowered:';
    $lines[] = '';
    foreach ($report['compiler_blockers'] as $msg) {
        $lines[] = '- `'.$msg.'`';
    }
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
