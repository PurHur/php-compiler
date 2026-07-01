<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\QuotemetaJitHelper;
use PHPCompiler\ext\standard\VmString;
use PHPUnit\Framework\TestCase;

/** quotemeta() JIT routes through QuotemetaJitHelper PHP not inline LLVM (#14705). */
final class QuotemetaRuntimeShrinkTest extends TestCase
{
    public function testStringQuotemetaUsesJitHelperNotInlineLlvm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringQuotemeta.php');
        $this->assertStringContainsString('QuotemetaJitHelper', $source);
        $this->assertStringNotContainsString('quotemeta_count_head', $source);
        $this->assertStringNotContainsString('shouldEscape', $source);
    }

    public function testQuotemetaJitHelperDelegatesToVmString(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/QuotemetaJitHelper.php');
        $this->assertStringContainsString('VmString::quotemeta', $source);

        $expected = VmString::quotemeta('$a.b');
        $this->assertSame($expected, QuotemetaJitHelper::quotemetaArgv('$a.b'));
        $this->assertSame($expected, VmString::quotemeta('$a.b'));
    }

    public function testSpineBundleIncludesQuotemetaJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('QuotemetaJitHelper.php', $spine);
        $this->assertStringContainsString('StringQuotemeta.php', $spine);
    }
}
