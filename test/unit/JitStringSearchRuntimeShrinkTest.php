<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** stripos() CI JIT uses LLVM memcasecmp, not libc strncasecmp (#14000 follow-up, #4146). */
final class JitStringSearchRuntimeShrinkTest extends TestCase
{
    public function testJitStringSearchCiPathUsesLlvmMemcasecmpNotLibc(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStringSearch.php');
        $this->assertStringContainsString('__phpc_string_memcasecmp', $source);
        $this->assertStringContainsString('emitMemcasecmpCi', $source);
        $this->assertStringNotContainsString("self::emitFindSubstrLoop(\$context, \$fn, 'strncasecmp')", $source);
    }

    public function testStrposJitRoutesThroughStrposJitHelperNotJitStrpos(): void
    {
        $strpos = (string) file_get_contents(__DIR__.'/../../ext/standard/strpos.php');
        $this->assertStringContainsString('StringStrpos::invoke', $strpos);
        $this->assertFileDoesNotExist(__DIR__.'/../../ext/standard/JitStrpos.php');
    }
}
