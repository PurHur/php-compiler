<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\HttpResponseJitHelper;
use PHPCompiler\ext\standard\PendingHeadersJitHelper;
use PHPUnit\Framework\TestCase;

/** PendingHeadersRuntime routes embed through PendingHeadersJitHelper PHP (#9545, #12898). */
final class PendingHeadersRuntimeShrinkTest extends TestCase
{
    public function testPendingHeadersRuntimeIsThinRouter(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/PendingHeadersRuntime.php');
        $this->assertStringContainsString('PendingHeadersJitBridge::implement', $runtime);
        $this->assertStringNotContainsString('PendingHeadersStandaloneLlvm', $runtime);
        $this->assertStringNotContainsString('implementAdd', $runtime);
        $this->assertStringNotContainsString('implementFlush', $runtime);
        $this->assertLessThan(35, substr_count($runtime, "\n") + 1);

        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/PendingHeadersStandaloneLlvm.php');
    }

    public function testPendingHeadersJitBridgeUsesHelperNotLlvmGlobals(): void
    {
        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/PendingHeadersJitBridge.php');
        $this->assertStringContainsString('PendingHeadersJitHelper', $bridge);
        $this->assertStringContainsString('NestedJitCompileScope', $bridge);
        $this->assertStringNotContainsString('phpc_pending_header_count', $bridge);
        $this->assertStringNotContainsString('phpc_pending_header_lines', $bridge);
        $this->assertStringNotContainsString('PendingHeadersStandaloneLlvm', $bridge);
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
