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
