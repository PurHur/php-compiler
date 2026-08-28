<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: SplFixedArray __debugInfo var_dump + OOB catchable exceptions (#19783, #21994).
 *
 * @see php-src ext/spl/spl_fixedarray.c spl_fixedarray_object_debug_info
 *
 * @group llvm
 * @group aot
 */
final class SplFixedArrayDebugInfo19783AotTest extends TestCase
{
    private const DEBUG_EXPECTED = <<<'OUT'
object(SplFixedArray)#1 (2) {
  [0]=>
  int(1)
  [1]=>
  NULL
}

OUT;

    private const OOB_EXPECTED = "caught:RuntimeException\nafter\n";

    public function testContextRegistersDebugInfoProxy(): void
    {
        $ctx = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString("'__debugInfo'", $ctx);
        $this->assertStringContainsString(
            "'offsetGet', 'offsetSet', 'offsetExists', 'offsetUnset', '__debugInfo'",
            $ctx
        );
    }

    public function testVmSplFixedArrayDebugInfoRepro(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/splfixedarray_debug_info.php');
        $this->assertNotFalse($code);
        $cwd = getcwd();
        chdir(dirname(__DIR__, 2));
        try {
            ob_start();
            $runtime->run($runtime->parseAndCompile($code, 'splfixedarray_debug_info.php'));
            $out = (string) ob_get_clean();
        } finally {
            chdir($cwd);
        }
        $this->assertMatchesRegularExpression(
            '/object\(SplFixedArray\)#\d+ \(2\) \{\n  \[0\]=>\n  int\(1\)\n  \[1\]=>\n  NULL\n\}\n/',
            $out
        );
    }

    public function testAotSplFixedArrayDebugInfoRepro(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $this->assertSame(self::DEBUG_EXPECTED, $this->runAot(dirname(__DIR__).'/repro/splfixedarray_debug_info.php'));
    }

    public function testAotSplFixedArrayOobCatchableRepro(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $this->assertSame(
            self::OOB_EXPECTED,
            $this->runAot(dirname(__DIR__).'/repro/splfixedarray_oob_catchable_21994.php')
        );
    }

    private function runAot(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $bin = sys_get_temp_dir().'/phpc_sfa19783_'.getmypid().'.bin';
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

            return implode("\n", $runOut)."\n";
        } finally {
            @unlink($bin);
        }
    }
}
