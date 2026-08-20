<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: ternary with property condition must seal JUMPIF before arms (#32912).
 *
 * Peer #32880 coalesce — compiling arms while prop_value_done stays open lets
 * NestedJIT plant a second terminator (Module.php:180). Seal the BB that defined
 * the condition, then retarget to arm entries.
 *
 * @see php-src Zend/zend_compile.c ternary / JMPZ
 *
 * @group llvm
 * @group aot
 */
final class TernaryPropJumpIf32912AotTest extends TestCase
{
    private const EXPECTED = "x|x|x\n";

    public function testVmTernaryPropJumpIf(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_32912_ternary_prop_jumpif_aot.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_32912_ternary_prop_jumpif_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotTernaryPropJumpIf(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_32912_ternary_prop_jumpif_aot.php';
        $bin = sys_get_temp_dir().'/phpc_issue_32912_'.getmypid().'.bin';
        $compile = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            $matched = 0;
            for ($i = 0; $i < 10; ++$i) {
                $runOut = [];
                $runRc = 0;
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $this->assertSame(0, $runRc, 'run '.($i + 1).': '.implode("\n", $runOut));
                $this->assertSame(self::EXPECTED, implode("\n", $runOut)."\n", 'run '.($i + 1));
                ++$matched;
            }
            $this->assertSame(10, $matched);
        } finally {
            @unlink($bin);
        }
    }
}
