<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\FileGetContentsJitHelper;
use PHPUnit\Framework\TestCase;

/** __compiler_file_get_contents: always PHP helper bridge (#15309, #19339, #29510). */
final class FileGetContentsRuntimeShrinkTest extends TestCase
{
    public function testStringFileGetContentsUsesPhpBridgeNotDeferKernel(): void
    {
        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringFileGetContents.php');
        $this->assertStringContainsString('FileGetContentsJitHelper', $bridge);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $bridge);
        $this->assertStringContainsString('extractStringPtrFromHelperResult', $bridge);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $bridge);
        $this->assertStringNotContainsString('JitFileGetContentsKernel', $bridge);
        $this->assertStringNotContainsString('JitFileGetContentsLibc::emitBody', $bridge);
        $this->assertStringNotContainsString('use PHPCompiler\\ext\\standard\\JitFileGetContentsLibc', $bridge);
        $this->assertStringNotContainsString('StringFileGetContentsLibc', $bridge);
        $this->assertStringNotContainsString('LibcExtern', $bridge);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringFileGetContentsLibc.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../ext/standard/JitFileGetContentsKernel.php');
    }

    /** Thin libc remains only behind phpc_file_get_contents_kernel (#26756 / #29510). */
    public function testLibcEmitLivesOnlyInKernelNotBridge(): void
    {
        $kernel = (string) file_get_contents(__DIR__.'/../../ext/standard/phpc_file_get_contents_kernel.php');
        $this->assertStringContainsString('JitFileGetContentsLibc::emitBody', $kernel);
        $helper = (string) file_get_contents(__DIR__.'/../../ext/standard/FileGetContentsJitHelper.php');
        $this->assertStringContainsString('phpc_file_get_contents_kernel', $helper);
    }

    /** NestedJIT must resolve the kernel Internal — else helper returns null (#26756 / #29510). */
    public function testNestedJitWhitelistIncludesFileGetContentsKernel(): void
    {
        $context = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertMatchesRegularExpression(
            "/'phpc_file_get_contents_kernel'/",
            $context
        );
        $this->assertStringContainsString("'phpc_readfile_kernel'", $context);
        $this->assertStringContainsString("'phpc_file_put_contents_kernel'", $context);
    }

    public function testFileGetContentsJitHelperUsesPhpcKernel(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/FileGetContentsJitHelper.php');
        $this->assertMatchesRegularExpression('/return\s+\\\\phpc_file_get_contents_kernel\s*\(/', $source);
        $this->assertFileExists(__DIR__.'/../../ext/standard/phpc_file_get_contents_kernel.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitFileGetContentsLibc.php');
    }

    public function testFileGetContentsJitHelperDelegatesToVmFs(): void
    {
        if (!\function_exists('phpc_file_get_contents_kernel')) {
            $this->markTestSkipped('phpc_file_get_contents_kernel requires compiler runtime');
        }
        $path = tempnam(sys_get_temp_dir(), 'phpc-fgc-');
        $this->assertNotFalse($path);
        file_put_contents($path, 'jit-helper-ok');

        $this->assertSame('jit-helper-ok', FileGetContentsJitHelper::readPathArgv($path));
        $this->assertNull(FileGetContentsJitHelper::readPathArgv($path.'/missing-15309'));

        @unlink($path);
    }

    public function testSpineBundleIncludesFileGetContentsPhpPath(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('FileGetContentsJitHelper.php', $spine);
        $this->assertStringContainsString('StringFileGetContents.php', $spine);
        $this->assertStringNotContainsString('JitFileGetContentsKernel.php', $spine);
        $this->assertStringNotContainsString('StringFileGetContentsLibc.php', $spine);
    }

    /** Inventory argv Zend helloworld link calls __compiler_file_get_contents from ensureFullStandaloneBodies (#15604). */
    public function testEnsureFullStandaloneBodiesLinksFileGetContents(): void
    {
        $context = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $fullPos = strpos($context, 'private function ensureFullStandaloneBodies');
        $this->assertNotFalse($fullPos);
        $fullBody = substr($context, $fullPos, 8000);
        $this->assertStringContainsString(
            'Builtin\\StringFileGetContents::ensureStandaloneBodies($this);',
            $fullBody
        );
    }
}
