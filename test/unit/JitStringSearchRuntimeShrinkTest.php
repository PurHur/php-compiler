<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** stripos() CI JIT uses FindSubstrJitHelper PHP, not inline LLVM memcasecmp loops (#15287). */
final class JitStringSearchRuntimeShrinkTest extends TestCase
{
    public function testJitStringSearchCiPathUsesPhpBridgeNotInlineLlvm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStringSearch.php');
        $this->assertStringContainsString('StringFindSubstr::invokeFindOffsetI32', $source);
        $this->assertStringNotContainsString('emitMemcasecmpCi', $source);
        $this->assertStringNotContainsString('__phpc_string_memcasecmp', $source);
    }

    public function testStrposJitRoutesThroughStrposJitHelperNotJitStrpos(): void
    {
        $strpos = (string) file_get_contents(__DIR__.'/../../ext/standard/strpos.php');
        $this->assertStringContainsString('StringStrpos::invoke', $strpos);
        $this->assertFileDoesNotExist(__DIR__.'/../../ext/standard/JitStrpos.php');
        $builtin = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringStrpos.php');
        $this->assertStringContainsString('VmStringCompare::findOffset', $builtin);
    }
}
