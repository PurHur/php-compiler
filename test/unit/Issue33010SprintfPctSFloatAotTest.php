<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT printf/sprintf("%s", float|int) must coerce, not SIGSEGV (#33010).
 *
 * @group llvm
 * @group aot
 */
final class Issue33010SprintfPctSFloatAotTest extends TestCase
{
    public function testAotSprintfPctSFloatMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_33010_sprintf_pct_s_float_aot.php';
        $bin = sys_get_temp_dir().'/phpc_issue_33010_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);

        $zendOut = [];
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1', $zendOut, $zendRc);
        $this->assertSame(0, $zendRc, implode("\n", $zendOut));
        $expected = implode("\n", $zendOut)."\n";

        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame($expected, implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
        }
    }

    public function testFormatOneArgCoercesBeforePctS(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/SprintfSnprintfRuntime.php');
        $this->assertStringContainsString('FMT_WANTS_STRING_ABI', $runtime);
        $this->assertStringContainsString('ZendDoubleStringRuntime::formatGcvt', $runtime);
        $this->assertStringContainsString('formatBoxedNativeLong', $runtime);
        $this->assertStringContainsString('#33010', $runtime);
        $this->assertStringContainsString('VmVariable::TYPE_STRING', $runtime);

        $jit = (string) file_get_contents(__DIR__.'/../../ext/standard/JitSprintf.php');
        $this->assertStringContainsString('__compiler_sprintf', $jit);
        $this->assertStringContainsString('#33010', $jit);
        $this->assertStringNotContainsString('extractSnprintfArg', $jit);
    }
}
