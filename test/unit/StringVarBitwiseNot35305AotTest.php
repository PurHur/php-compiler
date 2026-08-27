<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: unary ~ on boxed string variable matches Zend (#35305).
 *
 * @see php-src Zend/zend_operators.c bitwise_not_function
 *
 * @group llvm
 * @group aot
 */
final class StringVarBitwiseNot35305AotTest extends TestCase
{
    private const EXPECT = "string(1) \"\x9e\"\n9e\n9e9d\nstring(1) \"\x9e\"\n";

    public function testHelperTypeValueBitwiseNotBranchesOnStringTag(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Helper.php');
        $this->assertStringContainsString('bitwise_not_vbox_string', $source);
        $this->assertStringContainsString('__value__readString', $source);
        $this->assertMatchesRegularExpression(
            '/bitwise_not_vbox_string.*?StringBitwiseNot::emitUnary/s',
            $source
        );
    }

    public function testVmStringVarBitwiseNotMatchesZend(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/issue_35305_string_var_bitwise_not.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_35305_string_var_bitwise_not.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECT, $out);
    }

    public function testAotStringVarBitwiseNotMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_35305_string_var_bitwise_not.php';
        $bin = sys_get_temp_dir().'/phpc_issue_35305_'.getmypid().'.bin';
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
