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
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitSuperglobalRefreshKernel.php');
        $this->assertStringContainsString('ParseStrRuntime::ensureUserScriptLinked', $source);
        $this->assertStringContainsString('MultipartRuntime::ensureUserScriptLinked', $source);
        $this->assertStringContainsString('EnvironMirrorRuntime::ensureLinked', $source);
        $this->assertStringContainsString('EnvironMirrorRuntime::emitFillCall', $source);
        $this->assertStringContainsString('ensurePrerequisites', $source);
        $this->assertStringContainsString("storeLibcGetenvInEntry(\$context, \$entry, 'PATH_INFO')", $source);
        $this->assertStringContainsString('storeResolvedPathInfoInEntry', $source);
        $this->assertStringContainsString("setServerKeyFromCstr(\$context, \$serverHt, 'PATH_INFO'", $source);
        $this->assertStringContainsString('__compiler_parse_str', $source);
        $this->assertStringContainsString('__compiler_parse_cookie_header', $source);
        $this->assertStringContainsString('__compiler_multipart_populate_post_body', $source);
        $this->assertStringNotContainsString('ParseStrUserScriptDelimitedJit', $source);
        $this->assertStringNotContainsString('__phpc_parse_str_parse_delimited_pairs', $source);
        $this->assertStringNotContainsString('StringGetenvAll::ensureLinked', $source);
        $this->assertStringNotContainsString('GetenvJitHelper::fillAllEnvironmentHashtable', $source);
        $this->assertStringNotContainsString('ParseStrNativeLlvm::ensureSubhelpers', $source);
    }

    public function testUserScriptLlvmMovedToExtKernel(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/SuperglobalRefreshUserScriptLlvm.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitSuperglobalRefreshKernel.php');

        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/SuperglobalRefreshRuntime.php');
        $this->assertStringContainsString('JitSuperglobalRefreshKernel::implement', $runtime);
        $this->assertStringContainsString('JitSuperglobalRefreshKernel::ensurePrerequisites', $runtime);
        $this->assertStringContainsString('JitSuperglobalRefreshKernel::emitRefresh', $runtime);
        $this->assertStringNotContainsString('SuperglobalRefreshUserScriptLlvm', $runtime);

        $kernel = (string) file_get_contents(__DIR__.'/../../ext/standard/JitSuperglobalRefreshKernel.php');
        $this->assertStringContainsString('namespace PHPCompiler\\ext\\standard;', $kernel);
        $this->assertStringContainsString('final class JitSuperglobalRefreshKernel', $kernel);
        $this->assertStringContainsString('__superglobals__refresh', $kernel);
    }

    public function testSpineBundleIncludesSuperglobalRefreshKernelNotBuiltinUserScriptLlvm(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('JitSuperglobalRefreshKernel.php', $spine);
        $this->assertStringContainsString('SuperglobalRefreshRuntime.php', $spine);
        $this->assertStringNotContainsString('SuperglobalRefreshUserScriptLlvm.php', $spine);
    }
}
