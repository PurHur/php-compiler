<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\QuotemetaJitHelper;
use PHPCompiler\ext\standard\VmString;
use PHPUnit\Framework\TestCase;

/** quotemeta() JIT routes through QuotemetaJitHelper + JitVmHelperLink (#14705, #21589, #27011). */
final class QuotemetaRuntimeShrinkTest extends TestCase
{
    public function testStringQuotemetaUsesJitHelperNotInlineLlvm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringQuotemeta.php');
        $this->assertStringContainsString('QuotemetaJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('quotemeta_count_head', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../ext/standard/JitQuotemeta.php');

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/quotemeta.php');
        $this->assertStringContainsString('StringQuotemeta::ensureLinked', $builtin);
        $this->assertStringContainsString('__string__quotemeta', $builtin);
        $this->assertStringNotContainsString('JitQuotemeta', $builtin);
    }

    public function testQuotemetaJitHelperIsSelfContainedAndMatchesVmString(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/QuotemetaJitHelper.php');
        // NestedJIT AOT: no VmString / ExternalMethod stub (#16075 / #27011).
        $this->assertStringNotContainsString('VmString::', $source);
        $this->assertStringContainsString('escapeChar', $source);
        $this->assertStringNotContainsString('str_contains', $source);
        // No if-in-loop — that empties helper-runtime unit.o under NestedJIT emit (#27011).
        $this->assertDoesNotMatchRegularExpression(
            '/for\s*\([^)]+\)\s*\{[^}]*\bif\s*\(/s',
            $source
        );

        $expected = VmString::quotemeta('$a.b');
        $this->assertSame($expected, QuotemetaJitHelper::quotemetaArgv('$a.b'));
        $this->assertSame($expected, VmString::quotemeta('$a.b'));
        $this->assertSame(VmString::quotemeta('plain'), QuotemetaJitHelper::quotemetaArgv('plain'));
        $this->assertSame(VmString::quotemeta('.\\+*?[]^()$'), QuotemetaJitHelper::quotemetaArgv('.\\+*?[]^()$'));
        $this->assertSame('', QuotemetaJitHelper::quotemetaArgv(''));
    }

    public function testSpineBundleOmitsDeletedJitQuotemeta(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringNotContainsString('JitQuotemeta.php', $spine);
        $this->assertStringContainsString('QuotemetaJitHelper.php', $spine);
        $this->assertStringContainsString('StringQuotemeta.php', $spine);
    }
}
