<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: float**int / pow(float,int) match Zend (#35123 / re-#35058).
 *
 * NestedJIT dropped `$e = $exp` when followed by `if ($neg) $e = -$exp`, so successive
 * squaring broke immediately and returned 1.0.
 *
 * @see php-src ext/standard/math.c
 * @see php-src Zend/zend_operators.c — pow_function
 *
 * @group llvm
 * @group aot
 */
final class FpowIntExponent35123AotTest extends TestCase
{
    private const EXPECT = "star_fi=float(6.25)\n"
        ."pow_fi=float(6.25)\n"
        ."pow_ff=float(6.25)\n"
        ."var_star=float(6.25)\n"
        ."neg=float(0.16)\n"
        ."frac=float(11.313708498984761)\n";

    public function testHelperAvoidsNegBoolERewrite(): void
    {
        $fpow = (string) file_get_contents(__DIR__.'/../../ext/standard/FpowJitHelper.php');
        $this->assertStringContainsString('#35123', $fpow);
        $this->assertStringContainsString('powByIntPositive', $fpow);
        $this->assertDoesNotMatchRegularExpression(
            '/^\s*\$e\s*=\s*\$neg\s*\?\s*-\$exp\s*:\s*\$exp\s*;/m',
            $fpow
        );

        $powInt = (string) file_get_contents(__DIR__.'/../../ext/standard/PowIntJitHelper.php');
        $this->assertStringContainsString('#35123', $powInt);
        $this->assertStringContainsString('floatPowPositive', $powInt);
        $this->assertDoesNotMatchRegularExpression(
            '/^\s*\$e\s*=\s*\$neg\s*\?\s*-\$exp\s*:\s*\$exp\s*;/m',
            $powInt
        );
    }

    public function testVmFpowIntExponent(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/aot_fpow_int_exponent_35123.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'aot_fpow_int_exponent_35123.php'));
        $out = (string) ob_get_clean();
        $this->assertStringContainsString('star_fi=float(6.25)', $out);
        $this->assertStringContainsString('pow_fi=float(6.25)', $out);
        $this->assertStringContainsString('neg=float(0.16)', $out);
        $this->assertStringContainsString('frac=float(11.313708498984761)', $out);
    }

    public function testAotFpowIntExponent(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/aot_fpow_int_exponent_35123.php';
        $bin = sys_get_temp_dir().'/phpc_aot_fpow_int_35123_'.getmypid().'.bin';
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
