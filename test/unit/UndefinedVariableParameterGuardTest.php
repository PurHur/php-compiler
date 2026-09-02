<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * Typed parameters and per-frame assigned flags — no module-global guards (#36190).
 *
 * @group llvm
 * @group jit
 */
final class UndefinedVariableParameterGuardTest extends TestCase
{
    private function skipUnlessLlvmReady(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
    }

    public function testTypedParameterHasNoModuleGlobalAssignedFlagOrUndefGuard(): void
    {
        $this->skipUnlessLlvmReady();
        $code = <<<'PHP'
<?php
function fibo_r(int $n): int
{
    return ($n < 2) ? 1 : fibo_r($n - 2) + fibo_r($n - 1);
}
echo fibo_r(5), "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'undef_param_guard_fibo.php');
        $runtime->jitCompileBlock($block);
        $ir = $runtime->loadJitContext()->module->printToString();
        $this->assertStringNotContainsString(
            'phpc_scope_var_init',
            $ir,
            'assigned flags must be entry allocas, not module globals (#36190)'
        );
        $this->assertMatchesRegularExpression('/define i64 @fibo_r/', $ir);
        if (preg_match('/define i64 @fibo_r.*?^}/ms', $ir, $match)) {
            $this->assertStringNotContainsString(
                'trigger_error',
                $match[0],
                'typed parameters must not emit undefined-variable guards (#36190)'
            );
            $this->assertStringNotContainsString(
                'undef_var_warn',
                $match[0],
                'typed parameters must not branch on assigned flags (#36190)'
            );
        }
    }

    public function testRecursiveMaybeUndefinedLocalUsesEntryAllocaNotGlobal(): void
    {
        $this->skipUnlessLlvmReady();
        $code = <<<'PHP'
<?php
function f(int $d) {
    if ($d > 0) {
        $x = 1;
        f($d - 1);
    }
    echo isset($x) ? 'set' : 'unset', ' ', @$x, "\n";
}
f(1);
PHP;
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_undef_recur_'.getmypid().'.php';
        file_put_contents($src, $code);
        $bin = sys_get_temp_dir().'/phpc_undef_recur_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '
            .escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));

        $zendCmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1';
        exec($zendCmd, $zendOut, $zendRc);
        $this->assertSame(0, $zendRc, implode("\n", $zendOut));

        try {
            exec(escapeshellarg($bin).' 2>&1', $aotOut, $aotRc);
            $this->assertSame(0, $aotRc, implode("\n", $aotOut));
            $this->assertSame(implode("\n", $zendOut), implode("\n", $aotOut));
        } finally {
            @unlink($bin);
            @unlink($src);
        }

        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'undef_recur_alloca.php');
        $runtime->jitCompileBlock($block);
        $ir = $runtime->loadJitContext()->module->printToString();
        $this->assertStringNotContainsString(
            'phpc_scope_var_init',
            $ir,
            'maybe-undefined locals use per-frame entry allocas (#36190)'
        );
        if (preg_match('/define void @f.*?^}/ms', $ir, $match)) {
            $this->assertStringContainsString(
                'alloca i8',
                $match[0],
                'assigned flag should be an i8 entry alloca (#36190)'
            );
        }
    }
}
