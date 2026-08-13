<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\QuotemetaJitHelper;
use PHPCompiler\ext\standard\VmQuotemeta;
use PHPCompiler\ext\standard\VmString;
use PHPUnit\Framework\TestCase;

/**
 * quotemeta() JIT routes through QuotemetaJitHelper + VmQuotemeta
 * (#14705, #21589, #27011, #30858).
 */
final class QuotemetaRuntimeShrinkTest extends TestCase
{
    public function testStringQuotemetaUsesJitHelperBundle(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringQuotemeta.php');
        $this->assertStringContainsString('QuotemetaJitHelper', $source);
        $this->assertStringContainsString('VmQuotemeta.php', $source);
        $this->assertStringContainsString('HELPER_BUNDLE', $source);
        $this->assertStringContainsString('ensureCompiledBundle', $source);
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

    public function testUserScriptAotForcesNestedJitOfQuotemetaHelper(): void
    {
        $cache = (string) file_get_contents(__DIR__.'/../../lib/AOT/HelperRuntimeCache.php');
        $this->assertStringContainsString(
            "phpcompiler\\\\ext\\\\standard\\\\quotemetajithelper::quotemetaargv",
            $cache,
            'USER_SCRIPT_INLINE_ONLY must NestedJIT quotemetaArgv — prelinked unit.o SIGSEGVs (#30858)'
        );
    }

    /** #30858: NestedJIT-safe VmQuotemeta (strlen/substr; no $s[$i]). */
    public function testQuotemetaJitHelperDelegatesToVmQuotemeta(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/QuotemetaJitHelper.php');
        $this->assertStringContainsString('VmQuotemeta::quotemeta', $source);
        $this->assertStringNotContainsString('VmString::', $source);
        $this->assertStringNotContainsString('$str[$', $source);
        $this->assertStringNotContainsString('isset($', $source);

        $vm = (string) file_get_contents(__DIR__.'/../../ext/standard/VmQuotemeta.php');
        $this->assertStringContainsString('\\strlen(', $vm);
        $this->assertStringContainsString('\\substr(', $vm);
        $this->assertStringContainsString('advanceIdx', $vm);
        $this->assertStringNotContainsString('$string[$', $vm);
        $this->assertStringNotContainsString('isset($', $vm);
        $this->assertStringNotContainsString('escapeFrom', $vm);
    }

    public function testQuotemetaJitHelperMatchesVmString(): void
    {
        $expected = VmString::quotemeta('$a.b');
        $this->assertSame($expected, QuotemetaJitHelper::quotemetaArgv('$a.b'));
        $this->assertSame($expected, VmQuotemeta::quotemeta('$a.b'));
        $this->assertSame(VmString::quotemeta('plain'), QuotemetaJitHelper::quotemetaArgv('plain'));
        $this->assertSame(VmString::quotemeta('.\\+*?[]^()$'), QuotemetaJitHelper::quotemetaArgv('.\\+*?[]^()$'));
        $this->assertSame('', QuotemetaJitHelper::quotemetaArgv(''));
        $this->assertSame('a\\.b\\*c', QuotemetaJitHelper::quotemetaArgv('a.b*c'));
    }

    public function testSpineBundleIncludesVmQuotemeta(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringNotContainsString('JitQuotemeta.php', $spine);
        $this->assertStringContainsString('VmQuotemeta.php', $spine);
        $this->assertStringContainsString('QuotemetaJitHelper.php', $spine);
        $this->assertStringContainsString('StringQuotemeta.php', $spine);
    }
}
