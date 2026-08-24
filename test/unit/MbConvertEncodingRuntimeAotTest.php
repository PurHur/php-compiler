<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: mb_convert_encoding() runtime via MbConvertEncodingJitHelper (#34309 leftover of #6251).
 *
 * @see php-src ext/mbstring/mbstring.c PHP_FUNCTION(mb_convert_encoding)
 *
 * @group llvm
 * @group aot
 */
final class MbConvertEncodingRuntimeAotTest extends TestCase
{
    public function testAotRuntimeMatchMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $this->assertAotMatchesZend(
            __DIR__.'/../repro/mb_convert_encoding_runtime_aot.php',
            ['café', 'hello']
        );
    }

    public function testHelperAndLoweringPresent(): void
    {
        $root = dirname(__DIR__, 2);
        $helper = (string) file_get_contents($root.'/ext/mbstring/MbConvertEncodingJitHelper.php');
        $this->assertStringContainsString('function convertArgv', $helper);
        $this->assertStringContainsString('utf8ToLatin1', $helper);
        $runtime = (string) file_get_contents($root.'/lib/JIT/Builtin/MbConvertEncodingRuntime.php');
        $this->assertStringContainsString('convertHelper', $runtime);
        $src = (string) file_get_contents($root.'/ext/mbstring/mb_convert_encoding.php');
        $this->assertStringContainsString('JitMbConvertEncoding::invoke', $src);
        $this->assertStringNotContainsString(
            'mb_convert_encoding() is not lowered for JIT/AOT in this compiler build',
            $src
        );
        $this->assertFileDoesNotExist($root.'/lib/AOT/runtime/mb_convert_encoding.c');
    }

    /** @param list<string> $argv */
    private function assertAotMatchesZend(string $src, array $argv): void
    {
        $zend = $this->runPhp($src, $argv);
        $aot = $this->runAot($src, $argv);
        $this->assertSame($zend, $aot);
    }

    /** @param list<string> $argv */
    private function runPhp(string $src, array $argv): string
    {
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($src);
        foreach ($argv as $a) {
            $cmd .= ' '.escapeshellarg($a);
        }
        exec($cmd.' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));

        return implode("\n", $out);
    }

    /** @param list<string> $argv */
    private function runAot(string $src, array $argv): string
    {
        $root = dirname(__DIR__, 2);
        $bin = sys_get_temp_dir().'/mb_convert_encoding_'.getmypid().'_'.md5($src);
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
            $run = escapeshellarg($bin);
            foreach ($argv as $a) {
                $run .= ' '.escapeshellarg($a);
            }
            exec($run.' 2>&1', $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));

            return implode("\n", $out);
        } finally {
            chdir($cwd);
            @unlink($bin);
        }
    }
}
