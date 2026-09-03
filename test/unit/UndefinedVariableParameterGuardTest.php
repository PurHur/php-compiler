<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * Typed parameters and per-frame assigned flags (#36190).
 *
 * User-function CVs use entry allocas; {main} script CVs keep module globals so
 * __init__ and @main share one slot — assertions scope to user @fn bodies only.
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
        $this->assertMatchesRegularExpression('/define i64 @fibo_r/', $ir);
        $this->assertMatchesRegularExpression('/define i64 @fibo_r.*?^}/ms', $ir, 'fibo_r IR missing');
        preg_match('/define i64 @fibo_r.*?^}/ms', $ir, $fiboMatch);
        $fiboIr = $fiboMatch[0];
        $this->assertStringNotContainsString(
            'phpc_scope_var_init',
            $fiboIr,
            'user-function CV flags must be entry allocas, not module globals (#36190)'
        );
        $this->assertStringNotContainsString(
            'trigger_error',
            $fiboIr,
            'typed parameters must not emit undefined-variable guards (#36190)'
        );
        $this->assertStringNotContainsString(
            'undef_var_warn',
            $fiboIr,
            'typed parameters must not branch on assigned flags (#36190)'
        );
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
        $compile = escapeshellarg(PHP_BINARY).' '
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
        $fPos = strpos($ir, 'define %__value__ @f(');
        $this->assertNotFalse($fPos, 'function f IR missing');
        // User @f is large; scan a prefix for per-frame i8 allocas (not whole-module {main} globals).
        $fPrefix = substr($ir, $fPos, 20000);
        $this->assertStringNotContainsString(
            'phpc_scope_var_init',
            $fPrefix,
            'user-function maybe-undefined locals use per-frame entry allocas (#36190)'
        );
        $this->assertStringContainsString(
            'alloca i8',
            $fPrefix,
            'assigned flag should be an i8 entry alloca (#36190)'
        );
    }

    public function testAssignedLocalBeforeEchoHasNoUndefWarningAot(): void
    {
        $this->skipUnlessLlvmReady();
        $code = <<<'PHP'
<?php
function fibo_r(int $n): int
{
    return ($n < 2) ? 1 : fibo_r($n - 2) + fibo_r($n - 1);
}
function fibo(int $n): void
{
    $r = fibo_r($n);
    echo $r, "\n";
}
fibo(5);
PHP;
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_undef_assign_echo_'.getmypid().'.php';
        file_put_contents($src, $code);
        $bin = sys_get_temp_dir().'/phpc_undef_assign_echo_'.getmypid().'.bin';
        // Do not set PHP_COMPILER_HELPER_RUNTIME_O=0 — on the pinned image that path
        // segfaults even for `echo "hi"` (helper-runtime objects required).
        $compile = escapeshellarg(PHP_BINARY).' '
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
            $this->assertStringNotContainsString(
                'Undefined variable',
                implode("\n", $aotOut),
                'assigned locals must not warn on echo (#36405)'
            );
        } finally {
            @unlink($bin);
            @unlink($src);
        }
    }

    /**
     * Loop-carried float CVs lower mul/add through the vbox path and leave the
     * ASSIGN elided (MUL writes the named CV). The native-double←vbox store must
     * still flip the assigned flag (#36386 / #36405 respin).
     */
    public function testFloatMulAssignInLoopHasNoUndefWarningAot(): void
    {
        $this->skipUnlessLlvmReady();
        $code = <<<'PHP'
<?php
function f(): void {
    $zr = 0.0;
    for ($i = 0; $i < 2; ++$i) {
        $zr2 = $zr * $zr;
        echo $zr2, "\n";
    }
}
f();
PHP;
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_undef_float_loop_'.getmypid().'.php';
        file_put_contents($src, $code);
        $bin = sys_get_temp_dir().'/phpc_undef_float_loop_'.getmypid().'.bin';
        $compile = escapeshellarg(PHP_BINARY).' '
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
            $this->assertStringNotContainsString(
                'Undefined variable',
                implode("\n", $aotOut),
                'float mul assign in loop must not warn (#36405 respin / #36386)'
            );
        } finally {
            @unlink($bin);
            @unlink($src);
        }
    }
}
