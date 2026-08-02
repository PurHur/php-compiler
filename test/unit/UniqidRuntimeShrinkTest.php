<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\UniqidJitHelper;
use PHPUnit\Framework\TestCase;

/** uniqid() JIT routes through UniqidJitHelper PHP not inline LLVM (#14897, #26931). */
final class UniqidRuntimeShrinkTest extends TestCase
{
    public function testStringUniqidUsesJitHelperNotInlineLlvm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringUniqid.php');
        $this->assertStringContainsString('UniqidJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $source);
        $this->assertStringContainsString('BasicBlockHelper::tryGetInsertBlock', $source);
        $this->assertStringContainsString('JitDate::time', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../ext/standard/JitUniqid.php');

        $uniqid = (string) file_get_contents(__DIR__.'/../../ext/standard/uniqid.php');
        $this->assertStringContainsString('StringUniqid::ensureLinked', $uniqid);
        $this->assertStringContainsString('__compiler_uniqid', $uniqid);
        $this->assertStringContainsString('JitStringBuiltinArg', $uniqid);
        $this->assertStringNotContainsString('JitUniqid', $uniqid);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringUniqid.php');
        $this->assertStringContainsString('__compiler_uniqid', $bridge);
        $this->assertStringContainsString('UniqidJitHelper::formatArgv', $bridge);
    }

    public function testUniqidJitHelperIsSelfContained(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/UniqidJitHelper.php');
        $this->assertStringNotContainsString('VmString::uniqid(', $source);
        $this->assertStringNotContainsString('VmDate::', $source);
        $this->assertStringContainsString('formatArgv', $source);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringUniqid.php');
        $this->assertStringContainsString('UniqidJitHelper::formatArgv', $bridge);
        $this->assertStringContainsString('JitDate::time', $bridge);

        $fromHelper = UniqidJitHelper::formatArgv('pfx_', 0x6a6f38e3, 0xa8855, 1, 0x40000000);
        $this->assertStringStartsWith('pfx_', $fromHelper);
        $this->assertSame(27, strlen($fromHelper));
        $this->assertMatchesRegularExpression('/^pfx_[0-9a-f]{13}[0-9]\\.[0-9]{8}$/', $fromHelper);

        $plain = UniqidJitHelper::formatArgv('', 0x6a6f38e3, 0xa8855, 0, 0);
        $this->assertSame(13, strlen($plain));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{13}$/', $plain);

        $host = UniqidJitHelper::uniqidArgv('pfx_', 1);
        $this->assertStringStartsWith('pfx_', $host);
        $this->assertSame(27, strlen($host));
    }

    public function testSpineBundleIncludesUniqidJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringNotContainsString('JitUniqid.php', $spine);
        $this->assertStringContainsString('UniqidJitHelper.php', $spine);
        $this->assertStringContainsString('StringUniqid.php', $spine);
    }
}
