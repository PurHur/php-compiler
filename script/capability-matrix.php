#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Generate docs/capabilities.md from ext module registrations, opcode handlers, and PHPT coverage.
 *
 * Usage:
 *   php script/capability-matrix.php          # write docs/capabilities.md
 *   php script/capability-matrix.php --check  # exit 1 if committed file is stale
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$check = in_array('--check', $argv, true);
$outFile = $root . '/docs/capabilities.md';

const ISSUE_URL_BASE = 'https://github.com/PurHur/php-compiler/issues/';

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
 * Language constructs (classes, methods, visibility) tracked separately from builtins.
 *
 * @return list<array{
 *   id: string,
 *   construct: string,
 *   opcodes: list<string>,
 *   issue: int,
 *   notes: list<string>,
 *   probe: ?string
 * }>
 */
function syntaxRowDefinitions(): array
{
    return [
        [
            'id' => 'class_new',
            'construct' => '`class` / `new`',
            'opcodes' => ['TYPE_DECLARE_CLASS', 'TYPE_NEW'],
            'issue' => 58,
            'notes' => [],
            'probe' => 'class C { public int $x = 1; } echo (new C())->x;',
        ],
        [
            'id' => 'instance_methods',
            'construct' => 'Instance methods',
            'opcodes' => ['TYPE_DECLARE_METHOD', 'TYPE_METHODCALL_INIT'],
            'issue' => 58,
            'notes' => [],
            'probe' => 'class C { public function f(): string { return "ok"; } } echo (new C())->f();',
        ],
        [
            'id' => 'private_methods',
            'construct' => 'Private methods',
            'opcodes' => ['TYPE_DECLARE_METHOD', 'TYPE_METHODCALL_INIT'],
            'issue' => 145,
            'notes' => ['compiled; visibility rules not enforced'],
            'probe' => 'class C { private function f(): string { return "ok"; } public function g(): string { return $this->f(); } } echo (new C())->g();',
        ],
        [
            'id' => 'property_fetch',
            'construct' => 'Property fetch `$this->x`',
            'opcodes' => ['TYPE_PROPERTY_FETCH', 'TYPE_DECLARE_PROPERTY'],
            'issue' => 58,
            'notes' => [],
            'probe' => 'class C { public int $x = 1; public function f(): int { return $this->x; } } echo (new C())->f();',
        ],
        [
            'id' => 'instanceof',
            'construct' => '`instanceof`',
            'opcodes' => ['TYPE_INSTANCEOF'],
            'issue' => 138,
            'notes' => [],
            'probe' => 'class C {} echo ((new C()) instanceof C) ? "yes" : "no";',
        ],
    ];
}

/** @return array{vm: array<string, true>, jit: array<string, true>} */
function collectOpcodeHandlers(string $root): array
{
    $vm = opcodesHandledIn((string) file_get_contents($root . '/lib/VM.php'));
    $jitMain = opcodesHandledIn((string) file_get_contents($root . '/lib/JIT.php'));
    $jitPre = is_file($root . '/lib/JIT.pre')
        ? opcodesHandledIn((string) file_get_contents($root . '/lib/JIT.pre'))
        : [];
    $jit = $jitMain + $jitPre;

    return ['vm' => $vm, 'jit' => $jit];
}

/** @return array<string, true> */
function opcodesHandledIn(string $source): array
{
    $handled = [];
    if (preg_match_all('/case OpCode::(TYPE_[A-Z0-9_]+):/', $source, $matches)) {
        foreach ($matches[1] as $name) {
            $handled[$name] = true;
        }
    }

    return $handled;
}

function opcodesSupported(array $handled, array $required): bool
{
    foreach ($required as $opcode) {
        if (!isset($handled[$opcode])) {
            return false;
        }
    }

    return true;
}

function probeAotCompile(string $code): bool
{
    try {
        $runtime = new PHPCompiler\Runtime(PHPCompiler\Runtime::MODE_AOT);
        $block = $runtime->parseAndCompile('<?php ' . $code, 'syntax-probe.php');

        return $block !== null;
    } catch (\Throwable) {
        return false;
    }
}

/**
 * @param list<array{id: string, construct: string, opcodes: list<string>, issue: int, notes: list<string>, probe: ?string}> $definitions
 * @return list<array{construct: string, vm: bool, jit: bool, aot: bool, issue: int, notes: list<string>}>
 */
function collectSyntaxCapabilities(string $root, array $definitions, array $handlers): array
{
    $phpt = collectSyntaxPhptCoverage($root, $definitions);
    $rows = [];

    foreach ($definitions as $def) {
        $vm = opcodesSupported($handlers['vm'], $def['opcodes']);
        $jit = opcodesSupported($handlers['jit'], $def['opcodes']);
        $aot = is_string($def['probe']) && $def['probe'] !== ''
            ? probeAotCompile($def['probe'])
            : $jit;
        $notes = $def['notes'];
        foreach ($phpt[$def['id']] ?? [] as $tag) {
            $notes[] = $tag;
        }
        if (!$jit && $vm) {
            $notes[] = 'VM-only lowering';
        }
        $rows[] = [
            'construct' => $def['construct'],
            'vm' => $vm,
            'jit' => $jit,
            'aot' => $aot,
            'issue' => $def['issue'],
            'notes' => array_values(array_unique($notes)),
        ];
    }

    return $rows;
}

/**
 * @param list<array{id: string, construct: string, opcodes: list<string>, issue: int, notes: list<string>, probe: ?string}> $definitions
 * @return array<string, list<string>>
 */
function collectSyntaxPhptCoverage(string $root, array $definitions): array
{
    $coverage = [];
    foreach ($definitions as $def) {
        $coverage[$def['id']] = [];
    }

    $patterns = [
        'class_new' => '/\b(?:class\s+\w+|new\s+\w+)/',
        'instance_methods' => '/function\s+\w+\s*\(/',
        'private_methods' => '/\bprivate\s+function\b/',
        'property_fetch' => '/\$this->\w+/',
        'instanceof' => '/\binstanceof\b/',
    ];

    $scan = [];
    $compliance = $root . '/test/compliance/cases';
    if (is_dir($compliance)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($compliance));
        foreach ($it as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.phpt')) {
                $scan[] = $file->getPathname();
            }
        }
    }
    foreach (glob($root . '/test/bootstrap-aot/*.php') ?: [] as $php) {
        $scan[] = $php;
    }

    foreach ($scan as $path) {
        $content = (string) file_get_contents($path);
        $isPhpt = str_ends_with($path, '.phpt');
        $body = $isPhpt && preg_match('/--FILE--\s*\n<\?php(.*?)(?:--EXPECT|$)/s', $content, $m)
            ? $m[1]
            : $content;
        $tag = $isPhpt ? 'compliance PHPT' : 'bootstrap AOT';
        foreach ($patterns as $id => $pattern) {
            if (preg_match($pattern, $body)) {
                $coverage[$id][] = $tag;
            }
        }
    }

    foreach ($coverage as $id => $tags) {
        $coverage[$id] = array_values(array_unique($tags));
    }

    return $coverage;
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
 * @param array<string, array{vm: bool, jit: bool, aot: bool, notes: list<string>, module: string}> $capabilities
 * @param list<array{construct: string, vm: bool, jit: bool, aot: bool, issue: int, notes: list<string>}> $syntax
 */
function renderMarkdown(array $capabilities, array $syntax, array $phpt): string
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
            yesNo($row['vm']),
            yesNo($row['jit']),
            yesNo($row['aot']),
            $row['module'],
            $notes === [] ? '' : implode('; ', $notes)
        );
    }

    $lines[] = '';
    $lines[] = '## Language constructs';
    $lines[] = '';
    $lines[] = 'User-defined classes, methods, property access, and `instanceof`. Tracking issues: [#58]('
        . ISSUE_URL_BASE . '58), [#145](' . ISSUE_URL_BASE . '145), [#138](' . ISSUE_URL_BASE . '138).';
    $lines[] = '';
    $lines[] = '| Construct | VM | JIT | AOT | Issue | Notes |';
    $lines[] = '|-----------|:--:|:---:|:---:|-------|-------|';

    foreach ($syntax as $row) {
        $lines[] = sprintf(
            '| %s | %s | %s | %s | [#%d](%s%d) | %s |',
            $row['construct'],
            yesNo($row['vm']),
            yesNo($row['jit']),
            yesNo($row['aot']),
            $row['issue'],
            ISSUE_URL_BASE,
            $row['issue'],
            $row['notes'] === [] ? '' : implode('; ', $row['notes'])
        );
    }

    $lines[] = '';
    $lines[] = '_Builtin AOT uses the same LLVM path as JIT unless noted otherwise. Syntax AOT column reflects `Runtime::MODE_AOT` compile probes._';
    $lines[] = '';

    return implode("\n", $lines);
}

function yesNo(bool $value): string
{
    return $value ? 'yes' : 'no';
}

$capabilities = collectCapabilities($root);
$handlers = collectOpcodeHandlers($root);
$syntax = collectSyntaxCapabilities($root, syntaxRowDefinitions(), $handlers);
$phpt = collectPhptCoverage($root);
$markdown = renderMarkdown($capabilities, $syntax, $phpt);

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
    fwrite(STDOUT, 'docs/capabilities.md is up to date ('
        . count($capabilities) . ' builtins, ' . count($syntax) . " constructs).\n");
    exit(0);
}

if (!is_dir(dirname($outFile))) {
    mkdir(dirname($outFile), 0755, true);
}
file_put_contents($outFile, $markdown);
fwrite(STDOUT, "Wrote $outFile (" . count($capabilities) . ' builtins, ' . count($syntax) . " constructs).\n");
