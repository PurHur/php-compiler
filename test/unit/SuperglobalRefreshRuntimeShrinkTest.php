<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** Superglobal refresh JIT routes through SuperglobalRefreshJitHelper PHP not standalone LLVM (#9907, #13031). */
final class SuperglobalRefreshRuntimeShrinkTest extends TestCase
{
    public function testSuperglobalRefreshRuntimeUsesJitHelperBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/SuperglobalRefreshRuntime.php');
        $this->assertStringContainsString('SuperglobalRefreshJitHelper::buildGetTable', $source);
        $this->assertStringNotContainsString('SuperglobalRefreshStandaloneLlvm', $source);
        $this->assertStringNotContainsString('emitRefreshMain', $source);
        $this->assertStringNotContainsString('__phpc_sg_parse_form_encoded', $source);
        $this->assertStringNotContainsString('PHP_COMPILER_SUPERGLOBAL_REFRESH_PHP', $source);
    }

    public function testSuperglobalRefreshRuntimeShrunkAtLeastEightyPercent(): void
    {
        $loc = substr_count((string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/SuperglobalRefreshRuntime.php'), "\n") + 1;
        $this->assertLessThanOrEqual((int) floor(1901 * 0.2), $loc, 'SuperglobalRefreshRuntime.php LOC');
    }

    public function testStandaloneLlvmDeleted(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/SuperglobalRefreshStandaloneLlvm.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringMultipartStandaloneLlvm.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringMultipart.php');
    }

    public function testUserScriptRefreshRoutesThroughParseStrRuntimeBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/SuperglobalRefreshUserScriptLlvm.php');
        $this->assertStringContainsString('ParseStrRuntime::ensureLinked', $source);
        $this->assertStringContainsString('__compiler_parse_str', $source);
        $this->assertStringContainsString('__compiler_parse_cookie_header', $source);
        $this->assertStringNotContainsString('ParseStrNativeLlvm::ensureSubhelpers', $source);
        $this->assertStringNotContainsString('ParseStrUserScriptDelimitedJit::ensureSubhelpers', $source);
    }
}
