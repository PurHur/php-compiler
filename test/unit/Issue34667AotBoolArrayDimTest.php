<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: bool array dim read + isset match Zend (#34667).
 *
 * @group aot
 * @group llvm
 */
final class Issue34667AotBoolArrayDimTest extends TestCase
{
    private const EXPECT = "7\nbool(true)\n";

    public function testVmBoolArrayDim(): void
    {
        $runtime = new \PHPCompiler\Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/aot_bool_array_dim.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'aot_bool_array_dim.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECT, $out);
    }

    public function testAotBoolArrayDim(): void
    {
        if (!\PHPCompiler\LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/aot_bool_array_dim.php';
        $bin = sys_get_temp_dir().'/issue_34667_'.getmypid().'.bin';
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
