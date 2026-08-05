<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\CompilerVersion;
use PHPCompiler\LlvmToolchain;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * #26939 — AOT round(…, RoundingMode) must build under thin NestedJIT (HELPER_RUNTIME_O=0)
 * and print Zend/VM-identical results (re-#26800 module-verify / wrong-output class).
 *
 * @group llvm
 * @group aot
 * @runInSeparateProcess
 * @preserveGlobalState disabled
 */
final class RoundRoundingModeAot26939Test extends TestCase
{
    public function testAotRoundRoundingModeBuildsAndMatchesVm(): void
    {
        $repo = \realpath(__DIR__.'/../..');
        $this->assertNotFalse($repo);
        \putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
        $_SERVER['PHP_COMPILER_PROFILE'] = '8.4';
        if (!CompilerVersion::supportsRoundingModeEnum()) {
            $this->markTestSkipped('RoundingMode requires PHP_COMPILER_PROFILE≥8.4 (#26939)');
        }
        if (!LlvmToolchain::isReady($repo) && !LlvmToolchain::hasLibrary($repo)) {
            $this->markTestSkipped('LLVM 9 toolchain not available — #26939');
        }

        $source = $repo.'/test/repro/issue_26939_aot_round_roundingmode.php';
        $this->assertFileExists($source);
        $out = $repo.'/build/test-aot-round-roundingmode-26939';
        @mkdir($repo.'/build', 0777, true);
        @unlink($out);

        $vmOut = [];
        $vmRc = 0;
        exec(
            'PHP_COMPILER_PROFILE=8.4 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($repo.'/bin/vm.php').' '.escapeshellarg($source).' 2>&1',
            $vmOut,
            $vmRc
        );
        $this->assertSame(0, $vmRc, implode("\n", $vmOut));
        $want = implode("\n", $vmOut)."\n";
        $this->assertSame("2\n1\n", $want);

        \putenv('PHP_COMPILER_HELPER_RUNTIME_O=0');
        $_ENV['PHP_COMPILER_HELPER_RUNTIME_O'] = '0';
        $compile = 'PHP_COMPILER_PROFILE=8.4 PHP_COMPILER_HELPER_RUNTIME_O=0 '
            .escapeshellarg(PHP_BINARY).' '.escapeshellarg($repo.'/bin/compile.php')
            .' -o '.escapeshellarg($out).' '.escapeshellarg($source).' 2>&1';
        $compileOut = [];
        $compileRc = 0;
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($out);
        $this->assertStringNotContainsString('Module verification failed', implode("\n", $compileOut));

        try {
            for ($i = 0; $i < 3; ++$i) {
                $runOut = [];
                $runRc = 0;
                exec(escapeshellarg($out).' 2>&1', $runOut, $runRc);
                $this->assertSame(0, $runRc, 'run '.($i + 1).': '.implode("\n", $runOut));
                $this->assertSame($want, implode("\n", $runOut)."\n", 'run '.($i + 1));
            }
        } finally {
            \putenv('PHP_COMPILER_HELPER_RUNTIME_O');
            unset($_ENV['PHP_COMPILER_HELPER_RUNTIME_O']);
            \putenv('PHP_COMPILER_PROFILE');
            @unlink($out);
        }
    }

    public function testFoldResolvesRoundingModeEnum(): void
    {
        $jitRound = (string) file_get_contents(__DIR__.'/../../ext/standard/JitRound.php');
        $this->assertStringContainsString('RoundingModeJit::compileTimeRoundMode', $jitRound);
        $this->assertStringContainsString('26939', $jitRound);
    }
}
