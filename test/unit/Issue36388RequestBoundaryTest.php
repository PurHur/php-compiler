<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\JIT\Builtin\MemoryRuntime;
use PHPUnit\Framework\TestCase;

/**
 * Request-boundary ABI for long-lived workers (#36388).
 */
final class Issue36388RequestBoundaryTest extends TestCase
{
    public function testStandaloneMainEmitsRequestBoundaryCalls(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString('MemoryRuntime::emitRequestBeginForStandaloneMain', $source);
        $this->assertStringContainsString('MemoryRuntime::emitRequestEndForStandaloneMain', $source);
        // request_end must follow shutdownFunc so arena release cannot UAF live zvals (#36388).
        $endPos = strpos($source, 'emitRequestEndForStandaloneMain');
        $shutdownPos = strpos($source, 'call($this->shutdownFunc)');
        $this->assertNotFalse($endPos);
        $this->assertNotFalse($shutdownPos);
        $this->assertLessThan($endPos, $shutdownPos, 'request_end must be after shutdownFunc');
    }

    public function testRuntimeRunEndsRequestAccounting(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/Runtime.php');
        $this->assertStringContainsString('MemoryAccounting::endRequest', $source);
        $this->assertStringContainsString('VmMemory::endRequest', $source);
    }

    public function testMemoryRuntimeExposesPublicRequestAbi(): void
    {
        $this->assertSame('phpc_request_begin', MemoryRuntime::REQUEST_BEGIN);
        $this->assertSame('phpc_request_end', MemoryRuntime::REQUEST_END);
    }
}
