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
        // Status remains in HttpResponseJitHelper PHP; only SAPI headers-sent flag is an LLVM global (#28929).
        $this->assertStringNotContainsString('__phpc_http_response_status_explicit', $source);
        $this->assertStringContainsString('__phpc_sapi_output_started', $source);
    }

    /** HttpResponseRuntime: JitVmHelperLink::ensureCompiled — no hand-rolled NestedJit compile (#21441). */
    public function testHttpResponseRuntimeUsesJitVmHelperLink(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/HttpResponseRuntime.php');
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
    }

    /** #35803: emitReset self-ensures; implement() restores insert block (#33965 / peer #35443). */
    public function testEmitResetForStandaloneMainSelfEnsures(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/HttpResponseRuntime.php');
        $pos = strpos($source, 'public static function emitResetForStandaloneMain');
        $this->assertNotFalse($pos);
        $next = strpos($source, 'public static function emitStandaloneStatusLine', $pos);
        $this->assertNotFalse($next);
        $body = substr($source, $pos, $next - $pos);
        $this->assertStringContainsString('self::ensureLinked($context)', $body);
        $ensurePos = strpos($body, 'self::ensureLinked($context)');
        $lookupPos = strpos($body, "__phpc_http_response_status_reset");
        $this->assertNotFalse($ensurePos);
        $this->assertNotFalse($lookupPos);
        $this->assertLessThan($lookupPos, $ensurePos);
    }

    public function testPendingHeadersUsesHttpResponseRuntimeNotGlobal(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/PendingHeadersJitBridge.php');
        $this->assertStringContainsString('HttpResponseRuntime::ensureLinked', $source);
        $this->assertStringNotContainsString('HttpResponseCode::$global', $source);
        $this->assertStringNotContainsString('PendingHeadersStandaloneLlvm', $source);
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
