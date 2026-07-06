<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\FileGetContentsJitHelper;
use PHPUnit\Framework\TestCase;

/** __compiler_file_get_contents: PHP helper for full JIT/self-host; libc defer for user-script AOT (#15309, #17036). */
final class FileGetContentsRuntimeShrinkTest extends TestCase
{
    public function testStringFileGetContentsUsesPhpBridgeWithUserScriptAotDefer(): void
    {
        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringFileGetContents.php');
        $this->assertStringContainsString('FileGetContentsJitHelper', $bridge);
        $this->assertStringContainsString('UserScriptAotDeferNestedJit', $bridge);
        $this->assertStringContainsString('StringFileGetContentsLibc', $bridge);
        $this->assertFileExists(__DIR__.'/../../lib/JIT/Builtin/StringFileGetContentsLibc.php');

        $libc = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringFileGetContentsLibc.php');
        $this->assertStringContainsString("lookupFunction('open')", $libc);
        $this->assertStringContainsString("lookupFunction('read')", $libc);
    }

    public function testFileGetContentsJitHelperDelegatesToVmFs(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'phpc-fgc-');
        $this->assertNotFalse($path);
        file_put_contents($path, 'jit-helper-ok');

        $this->assertSame('jit-helper-ok', FileGetContentsJitHelper::readPathArgv($path));
        $this->assertNull(FileGetContentsJitHelper::readPathArgv($path.'/missing-15309'));

        @unlink($path);
    }

    public function testSpineBundleIncludesFileGetContentsJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('FileGetContentsJitHelper.php', $spine);
        $this->assertStringContainsString('StringFileGetContents.php', $spine);
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
