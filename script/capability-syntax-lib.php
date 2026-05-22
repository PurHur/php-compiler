<?php

declare(strict_types=1);

/**
 * Shared language-construct capability helpers for capability-matrix.php and capability-syntax.php.
 */

const CAPABILITY_ISSUE_URL_BASE = 'https://github.com/PurHur/php-compiler/issues/';

/**
 * @return list<array{
 *   id: string,
 *   construct: string,
 *   opcodes: list<string>,
 *   issue: int,
 *   notes: list<string>,
 *   probe: ?string,
 *   aot?: bool
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
            'notes' => [],
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
            'id' => 'native_user_class',
            'construct' => 'Native user-class link (`phpc build --project`)',
            'opcodes' => ['TYPE_DECLARE_CLASS', 'TYPE_NEW', 'TYPE_METHODCALL_INIT'],
            'issue' => 568,
            'notes' => ['AOT native ABI — blocked #568'],
            'probe' => null,
            'aot' => false,
        ],
        [
            'id' => 'instanceof',
            'construct' => '`instanceof`',
            'opcodes' => ['TYPE_INSTANCEOF'],
            'issue' => 138,
            'notes' => [],
            'probe' => 'class C {} echo ((new C()) instanceof C) ? "yes" : "no";',
        ],
        [
            'id' => 'match_expr',
            'construct' => '`match` expression',
            'opcodes' => [],
            'issue' => 143,
            'notes' => [],
            'probe' => 'echo match (2) { 1 => "a", 2 => "b", default => "c" };',
        ],
        [
            'id' => 'arrow_functions',
            'construct' => 'Arrow functions `fn () =>`',
            'opcodes' => [],
            'issue' => 142,
            'notes' => [],
            'probe' => '$f = fn () => 1; echo $f();',
        ],
        [
            'id' => 'magic_const_class_method',
            'construct' => 'Magic constants `__CLASS__`, `__METHOD__`, `__FUNCTION__`',
            'opcodes' => ['TYPE_CONST_FETCH'],
            'issue' => 199,
            'notes' => ['Lowered at parse time via php-cfg MagicStringResolver'],
            'probe' => 'class C { public function id(): string { return __CLASS__ . "::" . __FUNCTION__; } } echo (new C)->id();',
        ],
        [
            'id' => 'magic_const_namespace',
            'construct' => 'Magic constant `__NAMESPACE__`',
            'opcodes' => ['TYPE_CONST_FETCH'],
            'issue' => 199,
            'notes' => ['Requires `namespace` declaration (#84)'],
            'probe' => null,
        ],
        [
            'id' => 'magic_const_dir_file',
            'construct' => 'Magic constants `__DIR__`, `__FILE__`',
            'opcodes' => ['TYPE_MAGIC_DIR', 'TYPE_MAGIC_FILE', 'TYPE_INCLUDE'],
            'issue' => 707,
            'notes' => ['Runtime script path stack; compile-time fold when path known (#85)'],
            'probe' => null,
        ],
        [
            'id' => 'magic_const_line',
            'construct' => 'Magic constant `__LINE__`',
            'opcodes' => ['TYPE_CONST_FETCH'],
            'issue' => 707,
            'notes' => ['Lowered at parse time via php-cfg (best-effort line)'],
            'probe' => null,
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

function probeCompile(string $code, int $mode): bool
{
    try {
        $runtime = new PHPCompiler\Runtime($mode);
        $block = $runtime->parseAndCompile('<?php ' . $code, 'syntax-probe.php');

        return $block !== null;
    } catch (\Throwable) {
        return false;
    }
}

function probeVmCompile(string $code): bool
{
    return probeCompile($code, PHPCompiler\Runtime::MODE_NORMAL);
}

function probeAotCompile(string $code): bool
{
    return probeCompile($code, PHPCompiler\Runtime::MODE_AOT);
}

/**
 * @param list<array{id: string, construct: string, opcodes: list<string>, issue: int, notes: list<string>, probe: ?string, aot?: bool}> $definitions
 * @return list<array{construct: string, vm: bool, jit: bool, aot: bool, issue: int, notes: list<string>}>
 */
function collectSyntaxCapabilities(string $root, array $definitions, array $handlers): array
{
    $phpt = collectSyntaxPhptCoverage($root, $definitions);
    $rows = [];

    foreach ($definitions as $def) {
        $opcodeDriven = $def['opcodes'] !== [];
        $vm = $opcodeDriven
            ? opcodesSupported($handlers['vm'], $def['opcodes'])
            : (is_string($def['probe']) && $def['probe'] !== '' && probeVmCompile($def['probe']));
        $jit = $opcodeDriven
            ? opcodesSupported($handlers['jit'], $def['opcodes'])
            : $vm;
        if (array_key_exists('aot', $def)) {
            $aot = $def['aot'];
        } elseif (is_string($def['probe']) && $def['probe'] !== '') {
            $aot = probeAotCompile($def['probe']);
        } else {
            $aot = $jit;
        }
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
 * @param list<array{id: string, construct: string, opcodes: list<string>, issue: int, notes: list<string>, probe: ?string, aot?: bool}> $definitions
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
        'native_user_class' => '/\b(?:class\s+\w+|new\s+\w+)/',
        'instanceof' => '/\binstanceof\b/',
        'match_expr' => '/\bmatch\s*\(/',
        'arrow_functions' => '/\bfn\s*\(/',
        'magic_const_class_method' => '/__CLASS__|__FUNCTION__|__METHOD__/',
        'magic_const_namespace' => '/__NAMESPACE__/',
        'magic_const_dir_file' => '/__DIR__|__FILE__/',
        'magic_const_line' => '/__LINE__/',
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
 * @param list<array{construct: string, vm: bool, jit: bool, aot: bool, issue: int, notes: list<string>}> $syntax
 */
function renderSyntaxMarkdown(array $syntax): string
{
    $lines = [
        '# Language construct capability matrix',
        '',
        'Auto-generated by `script/capability-syntax.php`. Do not edit by hand.',
        '',
        'User-defined classes, methods, visibility, `instanceof`, `match`, and arrow functions.',
        'Builtin functions are in [capabilities.md](capabilities.md).',
        '',
        'Tracking issues: [#58](' . CAPABILITY_ISSUE_URL_BASE . '58), [#145](' . CAPABILITY_ISSUE_URL_BASE
        . '145), [#138](' . CAPABILITY_ISSUE_URL_BASE . '138), [#568](' . CAPABILITY_ISSUE_URL_BASE
        . '568), [#143](' . CAPABILITY_ISSUE_URL_BASE . '143), [#142](' . CAPABILITY_ISSUE_URL_BASE . '142), [#199]('
        . CAPABILITY_ISSUE_URL_BASE . '199).',
        '',
        '| Construct | VM | JIT | AOT | Issue | Notes |',
        '|-----------|:--:|:---:|:---:|-------|-------|',
    ];

    foreach ($syntax as $row) {
        $lines[] = sprintf(
            '| %s | %s | %s | %s | [#%d](%s%d) | %s |',
            $row['construct'],
            capabilityYesNo($row['vm']),
            capabilityYesNo($row['jit']),
            capabilityYesNo($row['aot']),
            $row['issue'],
            CAPABILITY_ISSUE_URL_BASE,
            $row['issue'],
            $row['notes'] === [] ? '' : implode('; ', $row['notes'])
        );
    }

    $lines[] = '';
    $lines[] = '_Syntax AOT column reflects `Runtime::MODE_AOT` compile probes unless a row pins AOT (e.g. #568 native link)._';
    $lines[] = '';

    return implode("\n", $lines);
}

function capabilityYesNo(bool $value): string
{
    return $value ? 'yes' : 'no';
}

/**
 * Web north-star constructs for examples/003-MiniWebApp (issue #655).
 *
 * Status values are curated from closed/open ROADMAP issues, not compile probes.
 * VM/JIT/AOT cells use yes|no|partial|blocked|n/a.
 *
 * @return list<array{
 *   construct: string,
 *   vm: string,
 *   jit: string,
 *   aot: string,
 *   issue: int,
 *   notes: list<string>
 * }>
 */
function webNorthStarDefinitions(): array
{
    return [
        [
            'construct' => 'PATH_INFO / `?route=` fallback',
            'vm' => 'yes',
            'jit' => 'partial',
            'aot' => 'blocked',
            'issue' => 489,
            'notes' => ['#489 VM closed; AOT blocked #568'],
        ],
        [
            'construct' => '`phpc_deploy_path()` + `PHPC_DEPLOY_ROOT`',
            'vm' => 'yes',
            'jit' => 'partial',
            'aot' => 'blocked',
            'issue' => 585,
            'notes' => ['#585 closed; runtime deploy #623'],
        ],
        [
            'construct' => 'Runtime template `include` from deploy tree',
            'vm' => 'yes',
            'jit' => 'no',
            'aot' => 'blocked',
            'issue' => 623,
            'notes' => ['#623 VM/AOT lint; execute blocked #568'],
        ],
        [
            'construct' => 'CGI/1.1 driver (`bin/cgi.php`)',
            'vm' => 'yes',
            'jit' => 'n/a',
            'aot' => 'n/a',
            'issue' => 50,
            'notes' => ['#50 VM closed; #656 CgiDriverTest; #666 MiniWebApp PATH_INFO'],
        ],
        [
            'construct' => 'AOT CGI (`cgi-wrapper` + `phpc cgi`)',
            'vm' => 'n/a',
            'jit' => 'n/a',
            'aot' => 'partial',
            'issue' => 665,
            'notes' => ['#665 closed; 001 green; 003 blocked #568; #682'],
        ],
        [
            'construct' => 'FastCGI loop',
            'vm' => 'no',
            'jit' => 'no',
            'aot' => 'no',
            'issue' => 173,
            'notes' => [],
        ],
    ];
}

/** @param 'yes'|'no'|'partial'|'blocked' $status */
function capabilityStatusLabel(string $status): string
{
    return $status;
}

/**
 * @param list<array{construct: string, vm: string, jit: string, aot: string, issue: int, notes: list<string>}> $web
 */
function renderWebNorthStarMarkdown(array $web): string
{
    $lines = [
        '## Web north-star (`examples/003-MiniWebApp`)',
        '',
        'PATH_INFO routing, deploy-root includes, and CGI drivers for the reference web app.',
        'ROADMAP Phase 3/4: [#78](' . CAPABILITY_ISSUE_URL_BASE . '78), runtime tracker [#539]('
        . CAPABILITY_ISSUE_URL_BASE . '539). Builtin matrix: [capabilities.md](capabilities.md).',
        '',
        '| Construct | VM | JIT | AOT | Issue | Notes |',
        '|-----------|:--:|:---:|:---:|-------|-------|',
    ];

    foreach ($web as $row) {
        $lines[] = sprintf(
            '| %s | %s | %s | %s | [#%d](%s%d) | %s |',
            $row['construct'],
            capabilityStatusLabel($row['vm']),
            capabilityStatusLabel($row['jit']),
            capabilityStatusLabel($row['aot']),
            $row['issue'],
            CAPABILITY_ISSUE_URL_BASE,
            $row['issue'],
            $row['notes'] === [] ? '' : implode('; ', $row['notes'])
        );
    }

    $lines[] = '';
    $lines[] = '_Web rows are curated from ROADMAP issue state; AOT execute for user classes remains blocked by [#568]('
        . CAPABILITY_ISSUE_URL_BASE . '568)._';
    $lines[] = '';

    return implode("\n", $lines);
}
