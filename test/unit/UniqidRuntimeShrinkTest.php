<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\UniqidJitHelper;
use PHPCompiler\ext\standard\VmString;
use PHPUnit\Framework\TestCase;

/** uniqid() JIT routes through UniqidJitHelper PHP not inline LLVM (#14897). */
final class UniqidRuntimeShrinkTest extends TestCase
{
    public function testStringUniqidUsesJitHelperNotInlineLlvm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringUniqid.php');
        $this->assertStringContainsString('UniqidJitHelper', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../ext/standard/JitUniqid.php');

        $uniqid = (string) file_get_contents(__DIR__.'/../../ext/standard/uniqid.php');
        $this->assertStringContainsString('StringUniqid::ensureLinked', $uniqid);
        $this->assertStringContainsString('__compiler_uniqid', $uniqid);
        $this->assertStringContainsString('coerceZparamStrBuiltinArg', $uniqid);
        $this->assertStringContainsString('lowerZparamStr', $uniqid);
        $this->assertStringNotContainsString('JitUniqid', $uniqid);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringUniqid.php');
        $this->assertStringContainsString('__compiler_uniqid', $bridge);
    }

    public function testUniqidJitHelperDelegatesToVmString(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/UniqidJitHelper.php');
        $this->assertStringContainsString('VmString::uniqid', $source);

        $fromHelper = UniqidJitHelper::uniqidArgv('pfx_', 1);
        $this->assertStringStartsWith('pfx_', $fromHelper);
        $this->assertSame(27, strlen($fromHelper));
        $this->assertMatchesRegularExpression('/^pfx_[0-9a-f]{13}[0-9]\\.[0-9]{8}$/', $fromHelper);

        $plain = UniqidJitHelper::uniqidArgv('', 0);
        $this->assertSame(13, strlen($plain));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{13}$/', $plain);
    }

    public function testSpineBundleIncludesUniqidJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringNotContainsString('JitUniqid.php', $spine);
        $this->assertStringContainsString('UniqidJitHelper.php', $spine);
        $this->assertStringContainsString('StringUniqid.php', $spine);
    }
}
