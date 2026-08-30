<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * AOT: `$r =& $obj->prop; $r ??= n` must update the property (#35987 leftover of #35898).
 *
 * @see php-src Zend/zend_execute.c ZEND_COALESCE / zend_assign_to_variable_reference
 * @see php-src Zend/zend_object_handlers.c zend_std_get_property_ptr_ptr
 *
 * @group llvm
 * @group aot
 */
final class AotPropByrefCoalesceTest extends TestCase
{
    private const EXPECT = "5|7|7|7|7|3|3|1\n";

    public function testVmPropByrefCoalesceMatchesZend(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/aot_prop_byref_coalesce.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'aot_prop_byref_coalesce.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECT, $out);
    }

    public function testAotPropByrefCoalesceMatchesVm(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/aot_prop_byref_coalesce.php';
        $bin = sys_get_temp_dir().'/phpc_aot_prop_ref_coal_'.getmypid().'.bin';
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
