<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: exp()/pow()/** float exponents match Zend (#35058).
 *
 * @see php-src ext/standard/math.c
 * @see php-src Zend/zend_operators.c — pow_function
 *
 * @group llvm
 * @group aot
 */
final class ExpPowFloatExponent35058AotTest extends TestCase
{
    private const EXPECT = "exp1=2.718281828459\n"
        ."exp2=7.3890560989307\n"
        ."sqrt9=3\n"
        ."sqrt4=2\n"
        ."p23_5=11.313708498985\n"
        ."star=11.313708498985\n"
        ."var_pow=11.313708498985\n"
        ."var_star=11.313708498985\n"
        ."int_pow=int(8)\n"
        ."lit_int=int(8)\n";

    public function testHelperScaleLoopsAvoidCompoundAnd(): void
    {
        $exp = (string) file_get_contents(__DIR__.'/../../ext/standard/ExpJitHelper.php');
        $fpow = (string) file_get_contents(__DIR__.'/../../ext/standard/FpowJitHelper.php');
        $this->assertStringContainsString('#35058', $exp);
        $this->assertStringContainsString('if ($n > 0)', $exp);
        $this->assertStringNotContainsString('$i < $absN && $i < 1024', $exp);
        $this->assertStringNotContainsString('$i < $absN && $i < 1024', $fpow);

        $jitPow = (string) file_get_contents(__DIR__.'/../../ext/standard/JitPow.php');
        $this->assertStringContainsString('invokeBoxedRuntimeDispatch', $jitPow);
        $this->assertStringContainsString('valueBoxToDouble', (string) file_get_contents(
            __DIR__.'/../../ext/standard/pow.php'
        ));
    }

    public function testVmExpPowFloatExponent(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/aot_exp_pow_float_exponent.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'aot_exp_pow_float_exponent.php'));
        $out = (string) ob_get_clean();
        // VM may print one extra float digit on exp(1); require int path + fractional pow shape.
        $this->assertStringContainsString('sqrt9=3', $out);
        $this->assertStringContainsString('p23_5=11.313708498985', $out);
        $this->assertStringContainsString('var_pow=11.313708498985', $out);
        $this->assertStringContainsString("int_pow=int(8)\n", $out);
    }

    public function testAotExpPowFloatExponent(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/aot_exp_pow_float_exponent.php';
        $bin = sys_get_temp_dir().'/phpc_aot_exp_pow_35058_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame(self::EXPECT, implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
        }
    }
}
