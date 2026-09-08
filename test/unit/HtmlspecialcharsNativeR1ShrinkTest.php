<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * htmlspecialchars() thin AOT uses native phpc_htmlspecialchars_r1 (#36388).
 */
final class HtmlspecialcharsNativeR1ShrinkTest extends TestCase
{
    public function testStringHtmlspecialcharsUsesNativeR1NotNestedJit(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringHtmlspecialchars.php');
        $this->assertStringContainsString('HtmlspecialcharsRuntime', $source);
        $this->assertStringContainsString('phpc_htmlspecialchars_r1', $source);
        $this->assertStringNotContainsString('JitVmHelperLink::ensureBridge', $source);
        $this->assertStringNotContainsString('ensureCompiledBundle', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('HELPER_PATH', $source);

        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/HtmlspecialcharsRuntime.php');
        $this->assertStringContainsString('phpc_htmlspecialchars_r1', $runtime);
        $this->assertStringContainsString('__string__alloc', $runtime);
        $this->assertStringContainsString('&amp;', $runtime);

        $jit = (string) file_get_contents(__DIR__.'/../../ext/standard/JitHtmlspecialchars.php');
        $this->assertStringContainsString('StringHtmlspecialchars::invoke', $jit);
        $this->assertStringNotContainsString('__string__htmlspecialchars', $jit);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/htmlspecialchars.php');
        $this->assertStringContainsString('phpc_htmlspecialchars_r1', $builtin);
        $this->assertStringContainsString('releaseEphemeralArgAfterCopy', $builtin);
    }

    public function testOwningCallResultListsHtmlspecialchars(): void
    {
        $src = (string) file_get_contents(
            __DIR__.'/../../lib/JIT/Concern/CallResultOperandAssign.php'
        );
        $this->assertStringContainsString("'htmlspecialchars' => true", $src);
    }

    public function testSpineBundleIncludesHtmlspecialcharsRuntime(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('HtmlspecialcharsRuntime.php', $spine);
        $this->assertStringContainsString('StringHtmlspecialchars.php', $spine);
    }
}
