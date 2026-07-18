<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * request_parse_body user-script AOT kernel quarantined in ext/standard (#19466, #5965, #20521).
 * Thin path gates on isThinStandaloneAotMain (no StreamIo defer bag).
 */
final class RequestParseBodyRuntimeShrinkTest extends TestCase
{
    public function testUserScriptLlvmMovedToExtKernel(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/RequestParseBodyUserScriptLlvm.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitRequestParseBodyKernel.php');

        $builtin = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/RequestParseBodyRuntime.php');
        $this->assertStringNotContainsString('RequestParseBodyUserScriptLlvm', $builtin);
        $this->assertStringContainsString('isThinStandaloneAotMain', $builtin);
        $this->assertStringNotContainsString('shouldDeferHeavyStreamIoEmitters', $builtin);
        $this->assertStringNotContainsString('StreamIoRuntime', $builtin);

        $callsite = (string) file_get_contents(__DIR__.'/../../ext/standard/request_parse_body.php');
        $this->assertStringContainsString('JitRequestParseBodyKernel::ensureLinked', $callsite);
        $this->assertStringContainsString('JitRequestParseBodyKernel::BRIDGE_NAME', $callsite);
        $this->assertStringContainsString('isThinStandaloneAotMain', $callsite);
        $this->assertStringNotContainsString('shouldDeferHeavyStreamIoEmitters', $callsite);
        $this->assertStringNotContainsString('StreamIoRuntime', $callsite);
        $this->assertStringNotContainsString('RequestParseBodyUserScriptLlvm', $callsite);

        $kernel = (string) file_get_contents(__DIR__.'/../../ext/standard/JitRequestParseBodyKernel.php');
        $this->assertStringContainsString('namespace PHPCompiler\\ext\\standard;', $kernel);
        $this->assertStringContainsString('final class JitRequestParseBodyKernel', $kernel);
        $this->assertStringContainsString('__compiler_request_parse_body_user_aot', $kernel);
        $this->assertStringContainsString('MultipartRuntime::RPB_MULTIPART_RUNTIME_FUNCTION', $kernel);
    }

    public function testSpineBundleIncludesRequestParseBodyKernelNotBuiltinUserScriptLlvm(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('JitRequestParseBodyKernel.php', $spine);
        $this->assertStringContainsString('RequestParseBodyRuntime.php', $spine);
        $this->assertStringNotContainsString('RequestParseBodyUserScriptLlvm.php', $spine);
    }
}
