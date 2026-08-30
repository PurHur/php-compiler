<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: mb_convert_encoding() illegal-byte substitution (#25207).
 *
 * @see php-src ext/mbstring/mbstring.c PHP_FUNCTION(mb_convert_encoding)
 *
 * @group llvm
 * @group aot
 */
final class MbConvertIllegalSubstAotTest extends TestCase
{
    public function testAotRuntimeMatchMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $this->assertAotMatchesZend(__DIR__.'/../repro/aot_mb_convert_illegal_subst_25207.php');
    }

    public function testHelperPresent(): void
    {
        $root = dirname(__DIR__, 2);
        $this->assertFileExists($root.'/ext/mbstring/MbConvertSubstJitHelper.php');
        $helper = (string) file_get_contents($root.'/ext/mbstring/MbConvertSubstJitHelper.php');
        $this->assertStringContainsString('substitutionOutputArgv', $helper);
        $runtime = (string) file_get_contents($root.'/lib/JIT/Builtin/MbConvertEncodingRuntime.php');
        $this->assertStringContainsString('substCodeValue', $runtime);
        $this->assertStringContainsString('callConvert', $runtime);
    }

    private function assertAotMatchesZend(string $src): void
    {
        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        $this->assertSame($zend, $aot);
    }

    private function runPhp(string $src): string
    {
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($src);
        exec($cmd.' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));

        return implode("\n", $out);
    }

    private function runAot(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $bin = sys_get_temp_dir().'/mb_illegal_subst_'.getmypid().'_'.md5($src);
        $cmd = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '
            .escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src);
        $cwd = getcwd();
        chdir($root);
        try {
            exec($cmd.' 2>&1', $compOut, $compRc);
            $this->assertSame(0, $compRc, implode("\n", $compOut));
            $this->assertFileExists($bin);
            exec(escapeshellarg($bin).' 2>&1', $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));

            return implode("\n", $out);
        } finally {
            chdir($cwd);
            @unlink($bin);
        }
    }
}
