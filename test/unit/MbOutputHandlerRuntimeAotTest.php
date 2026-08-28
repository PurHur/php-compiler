<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: mb_output_handler() runtime string via MbOutputHandlerJitHelper (#20014 leftover).
 *
 * @see php-src ext/mbstring/mbstring.c PHP_FUNCTION(mb_output_handler)
 *
 * @group llvm
 * @group aot
 */
final class MbOutputHandlerRuntimeAotTest extends TestCase
{
    public function testAotRuntimeArgsMatchZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $this->assertAotMatchesZend(__DIR__.'/../repro/aot_mb_output_handler_runtime.php');
    }

    public function testHelperAndLoweringPresent(): void
    {
        $root = dirname(__DIR__, 2);
        $helper = (string) file_get_contents($root.'/ext/mbstring/MbOutputHandlerJitHelper.php');
        $this->assertStringContainsString('function convertArgv', $helper);
        $this->assertStringContainsString('MbConvertEncodingJitHelper::convertArgv', $helper);
        $runtime = (string) file_get_contents($root.'/lib/JIT/Builtin/MbOutputHandlerRuntime.php');
        $this->assertStringContainsString('MbOutputHandlerJitHelper::convertArgv', $runtime);
        $jit = (string) file_get_contents($root.'/ext/mbstring/JitMbOutputHandler.php');
        $this->assertStringContainsString('MbOutputHandlerRuntime::ensureLinked', $jit);
        $src = (string) file_get_contents($root.'/ext/mbstring/mb_output_handler.php');
        $this->assertStringContainsString('JitMbOutputHandler::invoke', $src);
        $this->assertStringNotContainsString(
            'requires compile-time string and int arguments',
            $src
        );
        $this->assertFileDoesNotExist($root.'/lib/AOT/runtime/mb_output_handler.c');
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
        exec($cmd.' 2>/dev/null', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));

        return implode("\n", $out);
    }

    private function runAot(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $bin = sys_get_temp_dir().'/mb_output_handler_'.getmypid().'_'.md5($src);
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
            exec(escapeshellarg($bin).' 2>/dev/null', $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));

            return implode("\n", $out);
        } finally {
            chdir($cwd);
            @unlink($bin);
        }
    }
}
