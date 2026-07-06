<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\FileGetContentsJitHelper;
use PHPUnit\Framework\TestCase;

/** __compiler_file_get_contents JIT routes through FileGetContentsJitHelper PHP not libc LLVM (#15309). */
final class FileGetContentsRuntimeShrinkTest extends TestCase
{
    public function testStringFileGetContentsUsesPhpBridgeNotLibcOpen(): void
    {
        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringFileGetContents.php');
        $this->assertStringContainsString('FileGetContentsJitHelper', $bridge);
        $this->assertStringNotContainsString('StringFileGetContentsLibc', $bridge);
        $this->assertStringNotContainsString("lookupFunction('open')", $bridge);
        $this->assertStringNotContainsString("lookupFunction('read')", $bridge);
        $this->assertStringNotContainsString("lookupFunction('close')", $bridge);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringFileGetContentsLibc.php');
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
        $this->assertStringContainsString(
            'Builtin\\StringFileGetContents::ensureStandaloneBodies($this);',
            $context
        );
        $needle = 'Builtin\\StringFileGetContents::ensureStandaloneBodies($this);';
        $fullPos = strpos($context, 'private function ensureFullStandaloneBodies');
        $minimalPos = strpos($context, 'private function ensureMinimalUserStandaloneBodies');
        $this->assertNotFalse($fullPos);
        $this->assertNotFalse($minimalPos);
        $fullBody = substr($context, $fullPos, $minimalPos - $fullPos);
        $this->assertStringContainsString(
            'Builtin\\StatPathRuntime::ensureStandaloneBodies($this);',
            $fullBody
        );
        $this->assertStringContainsString($needle, $fullBody);
    }
}
