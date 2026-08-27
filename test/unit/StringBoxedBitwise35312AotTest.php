<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: boxed string &|^ matches Zend byte-wise (#35312).
 *
 * @see php-src Zend/zend_operators.c bitwise_and_function / bitwise_or_function / bitwise_xor_function
 *
 * @group llvm
 * @group aot
 */
final class StringBoxedBitwise35312AotTest extends TestCase
{
    private const EXPECT = "string(1) \"`\"\n63\n03\nstring(1) \"1\"\nstring(1) \"`\"\n";

    public function testHelperValueValueBitwiseUsesEmitBinary(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Helper.php');
        $this->assertStringContainsString('bitwise_vv_string', $source);
        $this->assertStringContainsString('emitBitwiseValueValue', $source);
        $this->assertMatchesRegularExpression(
            '/emitBitwiseValueValue.*?StringBitwiseNot::emitBinary/s',
            $source
        );
        $this->assertStringContainsString('#35312', $source);
        $jit = (string) file_get_contents(__DIR__.'/../../lib/JIT.php');
        $this->assertStringNotContainsString('compileValueBoxedBitwiseOp', $jit);
        $this->assertStringContainsString('#35312', $jit);
    }

    public function testVmBoxedStringBitwiseMatchesZend(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/issue_35312_boxed_string_bitwise.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_35312_boxed_string_bitwise.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECT, $out);
    }

    public function testAotBoxedStringBitwiseMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_35312_boxed_string_bitwise.php';
        $bin = sys_get_temp_dir().'/phpc_issue_35312_'.getmypid().'.bin';
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
