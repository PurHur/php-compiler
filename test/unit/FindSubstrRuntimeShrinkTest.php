<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\FindSubstrJitHelper;
use PHPCompiler\ext\standard\JitStringSearch;
use PHPCompiler\ext\standard\VmString;
use PHPUnit\Framework\TestCase;

/** JitStringSearch find_substr JIT routes through FindSubstrJitHelper PHP not inline LLVM (#15287). */
final class FindSubstrRuntimeShrinkTest extends TestCase
{
    public function testJitStringSearchUsesPhpBridgeNotInlineLlvm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStringSearch.php');
        $this->assertStringContainsString('StringFindSubstr::invokeFindOffsetI32', $source);
        $this->assertStringNotContainsString('emitFindSubstrLoop', $source);
        $this->assertStringNotContainsString('emitMemcasecmpCi', $source);
        $this->assertStringNotContainsString('__phpc_string_find_substr', $source);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringFindSubstr.php');
        $this->assertStringContainsString('FindSubstrJitHelper', $bridge);
        $this->assertStringContainsString('phpc_find_substr_offset', $bridge);
    }

    public function testFindSubstrJitHelperDelegatesToVmString(): void
    {
        $this->assertSame(
            1,
            FindSubstrJitHelper::findOffsetArgv('hello', 'ell', 0)
        );
        $this->assertSame(
            JitStringSearch::NOT_FOUND,
            FindSubstrJitHelper::findOffsetArgv('hello', 'z', 0)
        );
        $this->assertSame(
            JitStringSearch::NOT_FOUND,
            FindSubstrJitHelper::findOffsetArgv('hello', '', 0)
        );
        $pos = VmString::stripos('Hello', 'ELL', 0);
        $this->assertSame(
            false === $pos ? JitStringSearch::NOT_FOUND : $pos,
            FindSubstrJitHelper::findOffsetCiArgv('Hello', 'ELL', 0)
        );
    }

    public function testSpineBundleIncludesFindSubstrJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('FindSubstrJitHelper.php', $spine);
        $this->assertStringContainsString('StringFindSubstr.php', $spine);
    }
}
