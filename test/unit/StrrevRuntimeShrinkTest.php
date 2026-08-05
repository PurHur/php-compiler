<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\StrrevJitHelper;
use PHPCompiler\ext\standard\VmString;
use PHPUnit\Framework\TestCase;

/** strrev() JIT routes through StrrevJitHelper + JitVmHelperLink (#14566, #21648). */
final class StrrevRuntimeShrinkTest extends TestCase
{
    public function testStringStrrevUsesJitHelperNotInlineLlvm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringStrrev.php');
        $this->assertStringContainsString('StrrevJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('ensureJitHelperCompiled', $source);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/strrev.php');
        $this->assertStringContainsString('StringStrrev::ensureLinked', $builtin);
        $this->assertStringContainsString('__compiler_strrev', $builtin);
        $this->assertStringNotContainsString('strrev_head', $builtin);
        $this->assertStringNotContainsString('strrev_body', $builtin);
    }

    public function testStrrevJitHelperIsSelfContainedAndMatchesVmString(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/StrrevJitHelper.php');
        // NestedJIT AOT: no VmString / ExternalMethod stub (#16075 / #27007).
        $this->assertStringNotContainsString('VmString::', $source);
        $this->assertStringContainsString('$len - 1 - $i', $source);

        $this->assertSame('cba', StrrevJitHelper::strrevArgv('abc'));
        $this->assertSame('cba', VmString::strrev('abc'));
        $this->assertSame('', StrrevJitHelper::strrevArgv(''));
        $this->assertSame('a', StrrevJitHelper::strrevArgv('a'));
        $this->assertSame("\0ba", StrrevJitHelper::strrevArgv("ab\0"));
    }

    public function testSpineBundleIncludesStrrevJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('StrrevJitHelper.php', $spine);
        $this->assertStringContainsString('StringStrrev.php', $spine);
    }
}
