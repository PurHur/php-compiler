<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\StrrchrJitHelper;
use PHPCompiler\ext\standard\VmString;
use PHPUnit\Framework\TestCase;

/** strrchr() JIT routes through StrrchrJitHelper PHP not libc LLVM (#15406, #31458). */
final class StrrchrRuntimeShrinkTest extends TestCase
{
    public function testJitStrrchrUsesPhpBridgeNotLibc(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStrrchr.php');
        $this->assertStringContainsString('StringStrrchr::invoke', $source);
        $this->assertStringNotContainsString("lookupFunction('strrchr')", $source);
        $this->assertStringNotContainsString('string_trim::jitCopySlice', $source);
    }

    public function testStringStrrchrBridgeUsesStrrchrJitHelper(): void
    {
        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringStrrchr.php');
        $this->assertStringContainsString('StrrchrJitHelper', $bridge);
        $this->assertStringNotContainsString("lookupFunction('strrchr')", $bridge);
    }

    public function testAlwaysOnLibcStrrchrDroppedAfterModuleLocalEnsure(): void
    {
        $libc = (string) file_get_contents(__DIR__.'/../../lib/JIT/LibcExtern.php');
        $this->assertStringNotContainsString("'strrchr' =>", $libc);
        $this->assertStringContainsString('#31458', $libc);

        $module = (string) file_get_contents(__DIR__.'/../../ext/standard/Module.php');
        $this->assertStringContainsString('#31458', $module);
        $this->assertStringNotContainsString("lookupFunction('strrchr')", $module);
        $this->assertStringNotContainsString("addFunction('strrchr'", $module);

        $setup = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ReflectionSetup.php');
        $this->assertStringContainsString('ensureLibcStrrchr', $setup);
        $this->assertStringContainsString('#31458', $setup);

        $tempnam = (string) file_get_contents(__DIR__.'/../../ext/standard/JitTempnamKernel.php');
        $this->assertStringContainsString("'strrchr'", $tempnam);
        $this->assertStringContainsString('#31458', $tempnam);
    }

    public function testStrrchrJitHelperDelegatesToVmString(): void
    {
        $this->assertSame(
            VmString::strrchr('path/to/file.txt', '/'),
            StrrchrJitHelper::resolveArgv('path/to/file.txt', '/')
        );
        $this->assertNull(StrrchrJitHelper::resolveArgv('no-match', 'z'));
    }

    public function testSpineBundleIncludesStrrchrJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('StrrchrJitHelper.php', $spine);
        $this->assertStringContainsString('StringStrrchr.php', $spine);
    }
}
