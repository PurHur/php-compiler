<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: boxed array === must match VM/Zend (#23485).
 *
 * @group llvm
 * @group aot
 */
final class ArrayIdenticalCompareAot23485Test extends TestCase
{
    public function testVmArraySelfIdentical(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/maintainer_gap_array_combine_inline_array_keys.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'maintainer_gap_array_combine_inline_array_keys.php'));
        $out = (string) ob_get_clean();
        $this->assertSame('ok'."\n", $out);
    }

    public function testAotArrayCombineInlineArrayKeysMatchesVm(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/maintainer_gap_array_combine_inline_array_keys.php';
        $bin = sys_get_temp_dir().'/phpc_array_identical_23485_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        putenv('PHP_COMPILER_HELPER_RUNTIME_O=0');
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame('ok'."\n", implode("\n", $runOut)."\n");
        } finally {
            putenv('PHP_COMPILER_HELPER_RUNTIME_O');
            @unlink($bin);
        }
    }

    public function testVmValueCompareHandlesArrayIdenticalTag(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/lib/VM/VmValueCompare.php');
        $this->assertStringContainsString('identical_value_array', $src);
        $this->assertStringContainsString('isValueBoxArrayType', $src);
        $this->assertStringContainsString('identicalHashtablePair', $src);
    }
}
