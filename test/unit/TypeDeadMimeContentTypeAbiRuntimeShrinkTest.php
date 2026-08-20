<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on __compiler_mime_content_type ABI shell from Builtin\Type (#33034).
 *
 * NestedJIT/AOT bridge stays MimeContentTypeRuntime + MimeContentTypeJitHelper.
 * Runtime owner declares module-locally (getNamedFunction first) so leftover
 * Type empty decls cannot mint mime_content_type.1 (#31894 / #32122).
 */
final class TypeDeadMimeContentTypeAbiRuntimeShrinkTest extends TestCase
{
    public function testTypeBuiltinDropsLeftoverAlwaysOnMimeContentTypeAbi(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#33034', $type);
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]__compiler_mime_content_type[\'"]/',
            $type,
            'Builtin\\Type must not always-declare __compiler_mime_content_type (#33034)'
        );
        $this->assertStringNotContainsString(
            "registerFunction('__compiler_mime_content_type'",
            $type,
            'Builtin\\Type must not always-register __compiler_mime_content_type (#33034)'
        );
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        $this->assertStringContainsString("registerFunction('__compiler_tmpfile'", $type);
        $this->assertStringContainsString('MimeContentTypeRuntime::ensureLinked', $type);
    }

    public function testRuntimeOwnerDeclaresMimeContentTypeAbiModuleLocally(): void
    {
        $owner = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MimeContentTypeRuntime.php');
        $this->assertStringContainsString('#33034', $owner);
        $this->assertStringContainsString('getNamedFunction', $owner);
        $this->assertStringContainsString('addFunction', $owner);
        $this->assertStringContainsString('__compiler_mime_content_type', $owner);
        $this->assertFileExists(__DIR__.'/../../ext/standard/MimeContentTypeJitHelper.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitMimeContentType.php');
    }

    public function testTypeInitializeStillEnsureLinksMimeContentTypeRuntime(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('MimeContentTypeRuntime::ensureLinked($this->context)', $type);
    }

    public function testNoNewRuntimeCForMimeContentTypeAbi(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/mime_content_type.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/mime_content_type.c');
    }
}
