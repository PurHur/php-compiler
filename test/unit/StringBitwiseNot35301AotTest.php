<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: unary ~ on string links and matches Zend (#35301).
 *
 * @see php-src Zend/zend_operators.c bitwise_not_function
 *
 * @group llvm
 * @group aot
 */
final class StringBitwiseNot35301AotTest extends TestCase
{
    private const EXPECT = "string(1) \"\x9e\"\n9e\nca\n";

    public function testHelperUnaryOpEnsureLinkedBeforeLookup(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Helper.php');
        $this->assertMatchesRegularExpression(
            '/TYPE_BITWISE_NOT === \$opcode->type.*?StringBitwiseNot::ensureLinked/s',
            $source
        );
        $this->assertStringContainsString("lookupFunction('__string__bitwiseNot')", $source);
    }

    public function testVmStringBitwiseNotMatchesZend(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/issue_35301_string_bitwise_not.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_35301_string_bitwise_not.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECT, $out);
    }

    public function testAotStringBitwiseNotMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_35301_string_bitwise_not.php';
        $bin = sys_get_temp_dir().'/phpc_issue_35301_'.getmypid().'.bin';
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
