<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: local array by-value assign must COW-separate on write (#34508).
 *
 * @see php-src Zend/zend_hash.c zend_array_dup
 * @see php-src Zend/zend_variables.c zval_separate
 *
 * @group llvm
 * @group aot
 */
final class ArrayCowLocal34508AotTest extends TestCase
{
    private const EXPECTED = "append_a=array (\n"
        ."  0 => 1,\n"
        .")\n"
        ."append_b=array (\n"
        ."  0 => 1,\n"
        ."  1 => 2,\n"
        .")\n"
        ."index_c=array (\n"
        ."  0 => 1,\n"
        .")\n"
        ."index_d=array (\n"
        ."  0 => 9,\n"
        .")\n"
        ."param_e=array (\n"
        ."  0 => 1,\n"
        .")\n"
        ."param_f=array (\n"
        ."  0 => 1,\n"
        ."  1 => 2,\n"
        .")\n";

    public function testVmArrayCowMatchesZend(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_34508_aot_array_cow_local.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_34508_aot_array_cow_local.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotArrayCowSeparatesOnWrite(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34508_aot_array_cow_local.php';
        $bin = sys_get_temp_dir().'/phpc_issue_34508_cow_'.getmypid().'.bin';
        $compile = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            $matched = 0;
            for ($i = 0; $i < 5; ++$i) {
                $runOut = [];
                $runRc = 0;
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $this->assertSame(0, $runRc, 'run '.($i + 1).': '.implode("\n", $runOut));
                $this->assertSame(self::EXPECTED, implode("\n", $runOut)."\n", 'run '.($i + 1));
                ++$matched;
            }
            $this->assertSame(5, $matched);
        } finally {
            @unlink($bin);
        }
    }
}
