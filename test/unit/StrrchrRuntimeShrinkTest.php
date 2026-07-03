<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\StrrchrJitHelper;
use PHPCompiler\ext\standard\VmString;
use PHPUnit\Framework\TestCase;

/** strrchr() JIT routes through StrrchrJitHelper PHP not libc LLVM (#15406). */
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
