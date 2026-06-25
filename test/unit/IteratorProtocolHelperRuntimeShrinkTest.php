<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** Iterator protocol JIT routes through VmIteratorProtocol PHP SSOT (#10240). */
final class IteratorProtocolHelperRuntimeShrinkTest extends TestCase
{
    public function testIteratorProtocolHelperIsThinTrampoline(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/IteratorProtocolHelper.php');
        $this->assertStringContainsString('VmIteratorProtocol', $source);
        $this->assertStringNotContainsString('RuntimeIndirectInstanceMethodCall', $source);
        $this->assertStringNotContainsString('foreach_iter_maybe_next', $source);
        $this->assertStringNotContainsString('ITERATOR_IFACES_LC', $source);
        $this->assertLessThanOrEqual(150, substr_count($source, "\n") + 1);
    }

    public function testVmIteratorProtocolOwnsForeachLowering(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/VM/VmIteratorProtocol.php');
        $this->assertStringContainsString('compileForeachReset', $source);
        $this->assertStringContainsString('resolveIteratorMethodProxy', $source);
        $this->assertStringContainsString('ITERATOR_IFACES_LC', $source);
        $this->assertGreaterThan(250, substr_count($source, "\n") + 1);
    }
}
