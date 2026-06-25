<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\HttpResponseJitHelper;
use PHPCompiler\ext\standard\PendingHeadersJitHelper;
use PHPUnit\Framework\TestCase;

/** PendingHeadersRuntime routes embed through PendingHeadersJitHelper PHP (#9545). */
final class PendingHeadersRuntimeShrinkTest extends TestCase
{
    public function testPendingHeadersRuntimeIsThinRouter(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/PendingHeadersRuntime.php');
        $this->assertStringContainsString('PendingHeadersJitBridge::implement', $runtime);
        $this->assertStringContainsString('PendingHeadersStandaloneLlvm::implement', $runtime);
        $this->assertStringNotContainsString('implementAdd', $runtime);
        $this->assertStringNotContainsString('implementFlush', $runtime);
        $this->assertLessThan(40, substr_count($runtime, "\n") + 1);
    }

    public function testPendingHeadersJitBridgeUsesHelperNotLlvmGlobals(): void
    {
        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/PendingHeadersJitBridge.php');
        $this->assertStringContainsString('PendingHeadersJitHelper', $bridge);
        $this->assertStringContainsString('NestedJitCompileScope', $bridge);
        $this->assertStringNotContainsString('phpc_pending_header_count', $bridge);
        $this->assertStringNotContainsString('phpc_pending_header_lines', $bridge);
    }

    public function testStandaloneLlvmQuarantinesHeaderQueue(): void
    {
        $llvm = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/PendingHeadersStandaloneLlvm.php');
        $this->assertStringContainsString('appendSetcookieExpires', $llvm);
        $this->assertStringContainsString('HttpResponseRuntime::loadStatusRaw', $llvm);
        $this->assertStringNotContainsString('NestedJitCompileScope', $llvm);
        $this->assertStringNotContainsString('ensureJitHelperCompiled', $llvm);
    }

    public function testPendingHeadersJitHelperQueueSemantics(): void
    {
        PendingHeadersJitHelper::reset();
        HttpResponseJitHelper::reset();

        PendingHeadersJitHelper::enableHeaderQueue();
        PendingHeadersJitHelper::addHeader('X-Test: 1', 1);
        PendingHeadersJitHelper::addHeader('X-Test: 2', 1);
        $table = PendingHeadersJitHelper::listHeadersTable();
        $this->assertNull($table);

        putenv('GATEWAY_INTERFACE=CGI/1.1');
        try {
            $table = PendingHeadersJitHelper::listHeadersTable();
            $this->assertNotNull($table);
            $this->assertSame(1, $table->getNumElements());
        } finally {
            putenv('GATEWAY_INTERFACE');
        }

        PendingHeadersJitHelper::removeHeader('X-Test');
        $this->assertSame(0, PendingHeadersJitHelper::isFlushed());
        PendingHeadersJitHelper::flushResponseHeaders();
        $this->assertSame(1, PendingHeadersJitHelper::isFlushed());
    }

    public function testPendingHeadersJitHelperSetcookieUsesSetcookieLine(): void
    {
        PendingHeadersJitHelper::reset();
        PendingHeadersJitHelper::enableHeaderQueue();
        PendingHeadersJitHelper::addSetcookie('sid', 'abc', 0, '/path', '', 1, 0, '', 0);
        putenv('GATEWAY_INTERFACE=CGI/1.1');
        try {
            $table = PendingHeadersJitHelper::listHeadersTable();
            $this->assertNotNull($table);
            $line = $table->findIndex(0)?->toString();
            $this->assertIsString($line);
            $this->assertStringContainsString('Set-Cookie: sid=abc', $line);
            $this->assertStringContainsString('; path=/path', $line);
            $this->assertStringContainsString('; secure', $line);
        } finally {
            putenv('GATEWAY_INTERFACE');
        }
    }
}
