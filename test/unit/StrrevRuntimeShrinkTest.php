<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\StrrevJitHelper;
use PHPCompiler\ext\standard\VmString;
use PHPUnit\Framework\TestCase;

/** strrev() JIT routes through StrrevJitHelper PHP not inline LLVM (#14566). */
final class StrrevRuntimeShrinkTest extends TestCase
{
    public function testStringStrrevUsesJitHelperNotInlineLlvm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringStrrev.php');
        $this->assertStringContainsString('StrrevJitHelper', $source);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/strrev.php');
        $this->assertStringContainsString('StringStrrev::ensureLinked', $builtin);
        $this->assertStringContainsString('__compiler_strrev', $builtin);
        $this->assertStringNotContainsString('strrev_head', $builtin);
        $this->assertStringNotContainsString('strrev_body', $builtin);
    }

    public function testStrrevJitHelperDelegatesToVmString(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/StrrevJitHelper.php');
        $this->assertStringContainsString('VmString::strrev', $source);

        $this->assertSame('cba', StrrevJitHelper::strrevArgv('abc'));
        $this->assertSame('cba', VmString::strrev('abc'));
    }

    public function testSpineBundleIncludesStrrevJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('StrrevJitHelper.php', $spine);
        $this->assertStringContainsString('StringStrrev.php', $spine);
    }
}
