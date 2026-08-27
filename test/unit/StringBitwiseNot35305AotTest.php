<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: unary ~ on TYPE_VALUE string is byte-wise (#35305 leftover of #35301).
 *
 * @see php-src Zend/zend_operators.c bitwise_not_function
 *
 * @group llvm
 * @group aot
 */
final class StringBitwiseNot35305AotTest extends TestCase
{
    private const EXPECT = "9e\n9e9d\nstring(1) \"\x9e\"\nint(-6)\n";

    public function testHelperValueBitwiseNotBranchesOnStringTag(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Helper.php');
        $fn = strpos($source, 'function unaryOp');
        $this->assertNotFalse($fn);
        $end = strpos($source, 'function binaryOp', $fn);
        $this->assertNotFalse($end);
        $chunk = substr($source, $fn, $end - $fn);
        $this->assertStringContainsString('bitwise_not_vbox_string', $chunk);
        $this->assertStringContainsString('__value__readString', $chunk);
        $this->assertStringContainsString('#35305', $chunk);
        $this->assertStringContainsString('StringBitwiseNot::emitUnary', $chunk);
    }

    public function testVmValueStringBitwiseNotMatchesZend(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_35305_string_bitwise_not_value.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_35305_string_bitwise_not_value.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECT, $out);
    }

    /**
     * @group llvm
     * @group aot
     */
    public function testAotValueStringBitwiseNotMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_35305_string_bitwise_not_value.php';
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
