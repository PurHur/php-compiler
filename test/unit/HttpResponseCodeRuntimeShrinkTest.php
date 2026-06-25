<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\HttpResponseJitHelper;
use PHPUnit\Framework\TestCase;

/** HttpResponseCode JIT routes through HttpResponseJitHelper PHP, not LLVM globals (#9344). */
final class HttpResponseCodeRuntimeShrinkTest extends TestCase
{
    public function testHttpResponseCodeUsesJitHelperNotLlvmGlobals(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/HttpResponseCode.php');
        $this->assertStringContainsString('HttpResponseRuntime', $source);
        $this->assertStringNotContainsString('addGlobal($i32, self::GLOBAL_NAME)', $source);
        $this->assertStringNotContainsString('__phpc_http_response_status_explicit', $source);
    }

    public function testHttpResponseRuntimeRoutesThroughHttpResponseJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/HttpResponseRuntime.php');
        $this->assertStringContainsString('HttpResponseJitHelper', $source);
        $this->assertStringNotContainsString('addGlobal($i32,', $source);
    }

    /** Issue #11206: standalone C main wrapper must not re-enter implement() and clear the insert block. */
    public function testEmitResetForStandaloneMainDoesNotRelink(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/HttpResponseRuntime.php');
        $this->assertStringContainsString('function emitResetForStandaloneMain', $source);
        $this->assertStringNotContainsString(
            "emitResetForStandaloneMain(Context \$context): void\n    {\n        if (Builtin::LOAD_TYPE_STANDALONE !== \$context->loadType) {\n            return;\n        }\n        self::ensureLinked(\$context);",
            $source
        );
        $this->assertStringContainsString('__phpc_http_response_status_reset', $source);
    }

    public function testPendingHeadersUsesHttpResponseRuntimeNotGlobal(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/PendingHeadersStandaloneLlvm.php');
        $this->assertStringContainsString('HttpResponseRuntime::loadStatusRaw', $source);
        $this->assertStringNotContainsString('HttpResponseCode::$global', $source);
    }

    public function testHttpResponseJitHelperSemantics(): void
    {
        HttpResponseJitHelper::reset();

        $this->assertSame(-1, HttpResponseJitHelper::applyGetSentinel());
        $this->assertSame(-2, HttpResponseJitHelper::applySetLong(404));
        $this->assertSame(404, HttpResponseJitHelper::applyGetSentinel());

        $this->assertSame(404, HttpResponseJitHelper::applySetLong(500));
        $this->assertSame(500, HttpResponseJitHelper::getStatusRaw());

        HttpResponseJitHelper::reset();
        $this->assertSame(-1, HttpResponseJitHelper::applySetLong(0));
        $this->assertSame(-1, HttpResponseJitHelper::applySetLong(99));
    }
}
