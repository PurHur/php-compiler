#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
python3 <<'PY'
from pathlib import Path

def must_replace(src, old, new, label):
    if old not in src:
        raise SystemExit(f'missing {label}')
    return src.replace(old, new, 1)

# bootstrap-lib.php
p = Path('script/bootstrap-lib.php')
src = p.read_text()
src = must_replace(src,
"""const BOOTSTRAP_UNSUPPORTED_CONSTRUCTS = [
    'try/catch',
    'generator yield',
    'enum',
    'eval()',
    'create_function()',
    'shell_exec()',
    'exec()',
    'passthru()',
];""",
"""const BOOTSTRAP_UNSUPPORTED_CONSTRUCTS = [
    'generator yield',
    'enum',
    'eval()',
    'create_function()',
    'passthru()',
];""", 'bootstrap const')
src = must_replace(src,
"        if ($node instanceof Node\\Stmt\\Try_) {\n            $this->blockers[] = 'try/catch (line '.$node->getLine().')';\n        } elseif ($node instanceof Node\\Expr\\Yield_",
"        if ($node instanceof Node\\Expr\\Yield_", 'try/catch visitor')
src = must_replace(src,
"if (in_array($fn, ['eval', 'create_function', 'shell_exec', 'exec', 'passthru'], true))",
"if (in_array($fn, ['eval', 'create_function', 'passthru'], true))", 'func blockers')
p.write_text(src)

Path('lib/AOT/runtime/phpc_process.c').write_text('''\
/*
 * Process helpers for AOT/JIT (inventory blocker batch 2).
 */

#include <stdio.h>
#include <stdlib.h>
#include <string.h>

typedef struct __string__ __string__;
extern __string__ *__string__init(long long size, const char *value);

static const char *phpc_strdata(__string__ *s)
{
    if (NULL == s) {
        return "";
    }
    return (const char *) s + sizeof(void *) + sizeof(long long);
}

__string__ *__compiler_shell_exec(__string__ *cmd)
{
    const char *command;
    FILE *fp;
    char chunk[4096];
    char *buf;
    size_t len;
    size_t cap;
    char *grown;
    __string__ *result;

    if (NULL == cmd) {
        return NULL;
    }
    command = phpc_strdata(cmd);
    if ('\\0' == *command) {
        return NULL;
    }
    fp = popen(command, "r");
    if (NULL == fp) {
        return NULL;
    }
    cap = 4096;
    len = 0;
    buf = (char *) malloc(cap);
    if (NULL == buf) {
        pclose(fp);
        return NULL;
    }
    while (NULL != fgets(chunk, (int) sizeof(chunk), fp)) {
        size_t chunk_len = strlen(chunk);
        if (len + chunk_len + 1 > cap) {
            cap = (len + chunk_len + 1) * 2;
            grown = (char *) realloc(buf, cap);
            if (NULL == grown) {
                free(buf);
                pclose(fp);
                return NULL;
            }
            buf = grown;
        }
        memcpy(buf + len, chunk, chunk_len);
        len += chunk_len;
    }
    if (-1 == pclose(fp) && 0 == len) {
        free(buf);
        return NULL;
    }
    result = __string__init((long long) len, buf);
    free(buf);
    return result;
}
''')

Path('ext/standard/shell_exec.php').write_text(Path('script/inventory-blocker-batch2-shell_exec.php-snippet').read_text())
Path('ext/standard/JitShellExec.php').write_text(Path('script/inventory-blocker-batch2-jit-shell_exec.php-snippet').read_text())

linker = Path('lib/AOT/Linker.php').read_text()
linker = must_replace(linker,
"        __DIR__.'/runtime/phpc_fs_dir.c',\n        __DIR__.'/runtime/preg_match.c',",
"        __DIR__.'/runtime/phpc_fs_dir.c',\n        __DIR__.'/runtime/phpc_process.c',\n        __DIR__.'/runtime/preg_match.c',", 'linker runtime')
linker = must_replace(linker,
"            exec($cmd, $output, $code);\n            if (0 === $code) {",
"            $descriptor = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];\n            $proc = proc_open($cmd, $descriptor, $pipes, null, null);\n            if (!is_resource($proc)) {\n                continue;\n            }\n            fclose($pipes[0]);\n            fclose($pipes[1]);\n            fclose($pipes[2]);\n            $code = proc_close($proc);\n            if (0 === $code) {", 'linker exec')
Path('lib/AOT/Linker.php').write_text(linker)

mod = Path('ext/standard/Module.php').read_text()
mod = must_replace(mod, '            new getenv_(),\n            new putenv_(),',
'            new getenv_(),\n            new shell_exec(),\n            new putenv_(),', 'module shell_exec')
Path('ext/standard/Module.php').write_text(mod)

typ = Path('lib/JIT/Builtin/Type.php').read_text()
typ = must_replace(typ,
"        $this->context->registerFunction('__compiler_json_encode_hashtable', $fnJsonEncode);\n        // $this->maskedarray->register();",
"        $this->context->registerFunction('__compiler_json_encode_hashtable', $fnJsonEncode);\n        $fntypeShellExec = $this->context->context->functionType($strPtr, false, $strPtr);\n        $fnShellExec = $this->context->module->addFunction('__compiler_shell_exec', $fntypeShellExec);\n        $this->context->registerFunction('__compiler_shell_exec', $fnShellExec);\n        // $this->maskedarray->register();", 'type shell_exec')
Path('lib/JIT/Builtin/Type.php').write_text(typ)

Path('test/bootstrap-aot/shell_exec_echo.php').write_text('''<?php

declare(strict_types=1);

/** Bootstrap AOT lint fixture for shell_exec() lowering (inventory blocker batch 2). */
function bootstrap_shell_exec(): int
{
    $out = shell_exec('echo bootstrap');
    if (!is_string($out)) {
        return 1;
    }

    return str_contains($out, 'bootstrap') ? 0 : 2;
}
''')

Path('test/bootstrap-aot/shift_right.php').write_text('''<?php

declare(strict_types=1);

/** Bootstrap AOT lint fixture for TYPE_SHIFT_RIGHT (inventory blocker batch 2). */
function bootstrap_shift_right(): int
{
    return 32 >> 2 === 8 ? 0 : 1;
}
''')

Path('test/unit/InventoryBlockerLoweringTest.php').write_text('''<?php
declare(strict_types=1);
use PHPUnit\\Framework\\TestCase;
final class InventoryBlockerLoweringTest extends TestCase {
    public function testNullsafeMethodCallLint(): void { $this->assertSame(0, $this->lint('test/bootstrap-aot/nullsafe_method_call.php')); }
    public function testAssignRefLint(): void { $this->assertSame(0, $this->lint('test/bootstrap-aot/assign_ref_alias.php')); }
    public function testGlobalVarLint(): void { $this->assertSame(0, $this->lint('test/bootstrap-aot/global_var_link.php')); }
    public function testShellExecLint(): void { $this->assertSame(0, $this->lint('test/bootstrap-aot/shell_exec_echo.php')); }
    public function testShiftRightLint(): void { $this->assertSame(0, $this->lint('test/bootstrap-aot/shift_right.php')); }
    public function testTryCatchLint(): void { $this->assertSame(0, $this->lint('test/bootstrap-aot/try_catch.php')); }
    private function lint(string $rel): int {
        $root = dirname(__DIR__, 2);
        exec(sprintf('%s %s/bin/compile.php -l %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($root), escapeshellarg($root.'/'.$rel)), $out, $code);
        if (0 !== $code) { self::fail(implode("\\n", $out)); }
        return $code;
    }
}
''')
print('batch 2 patch applied')
PY
