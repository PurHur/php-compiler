<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ReadfileJitHelper;
use PHPUnit\Framework\TestCase;

/**
 * __compiler_readfile shrink guards (#9188, #19966, #29915).
 * Always-helper; libc leaf only from NestedJIT JitReadfileLibc.
 */
final class ReadfileRuntimeShrinkTest extends TestCase
{
    public function testStringReadfileAlwaysHelperNoThinFork(): void
    {
        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringReadfile.php');
        $this->assertStringContainsString('ReadfileJitHelper', $bridge);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $bridge);
        $this->assertStringContainsString('extractLongFromHelperResult', $bridge);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $bridge);
        $this->assertStringNotContainsString('isThinStandaloneAotMain', $bridge);
        $this->assertStringNotContainsString('implementLibcBody', $bridge);
        $this->assertStringNotContainsString('JitReadfileLibc::emitBody', $bridge);
        $this->assertStringNotContainsString('JitReadfileLibc::call', $bridge);
        $this->assertStringNotContainsString('use PHPCompiler\\ext\\standard\\JitReadfileLibc', $bridge);
        $this->assertStringNotContainsString('JitReadfileKernel', $bridge);
        $this->assertStringNotContainsString('phpc_readfile_kernel', $bridge);
        $this->assertStringNotContainsString('StringReadfileLibc', $bridge);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringReadfileLibc.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../ext/standard/JitReadfileKernel.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitReadfileLibc.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../ext/standard/phpc_readfile_kernel.php');
    }

    /** Thin libc remains only behind NestedJIT leaf JitReadfileLibc (#19966 / #29915). */
    public function testLibcEmitLivesOnlyInNestedJitLeafNotBridge(): void
    {
        $libc = (string) file_get_contents(__DIR__.'/../../ext/standard/JitReadfileLibc.php');
        $this->assertStringContainsString('public static function emitBody', $libc);
        $this->assertStringContainsString('public static function call', $libc);
        $helper = (string) file_get_contents(__DIR__.'/../../ext/standard/ReadfileJitHelper.php');
        $this->assertStringNotContainsString('phpc_readfile_kernel', $helper);
        $this->assertStringContainsString('readfile', $helper);
    }

    /** NestedJIT must resolve readfile — else helper returns -1 (#29915 / #29833). */
    public function testNestedJitWhitelistIncludesReadfileNotKernel(): void
    {
        $context = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertMatchesRegularExpression(
            "/'readfile'/",
            $context
        );
        $this->assertStringNotContainsString("'phpc_readfile_kernel'", $context);
    }

    public function testReadfileJitHelperUsesBuiltinNotKernel(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/ReadfileJitHelper.php');
        $this->assertMatchesRegularExpression('/@\\\\readfile\s*\(/', $source);
        $this->assertStringNotContainsString('phpc_readfile_kernel', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../ext/standard/phpc_readfile_kernel.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitReadfileLibc.php');

        $jit = (string) file_get_contents(__DIR__.'/../../ext/standard/JitReadfile.php');
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $jit);
        $this->assertStringContainsString('JitReadfileLibc::call', $jit);
    }

    public function testReadfileJitHelperReturnsMinusOneWhenOpenFails(): void
    {
        $this->assertSame(
            -1,
            ReadfileJitHelper::readfile(
                sys_get_temp_dir().'/phpc-no-such-readfile-'.bin2hex(random_bytes(4))
            )
        );
    }

    public function testReadfileJitHelperDelegatesToHostBuiltin(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'phpc-rf-');
        $this->assertNotFalse($path);
        file_put_contents($path, 'jit-helper-rf');

        ob_start();
        $n = ReadfileJitHelper::readfile($path);
        $out = (string) ob_get_clean();

        $this->assertSame(strlen('jit-helper-rf'), $n);
        $this->assertSame('jit-helper-rf', $out);

        @unlink($path);
    }

    public function testSpineBundleIncludesReadfilePhpPath(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('ReadfileJitHelper.php', $spine);
        $this->assertStringContainsString('StringReadfile.php', $spine);
        $this->assertStringContainsString('JitReadfileLibc.php', $spine);
        $this->assertStringNotContainsString('phpc_readfile_kernel.php', $spine);
        $this->assertStringNotContainsString('JitReadfileKernel.php', $spine);
    }
}
