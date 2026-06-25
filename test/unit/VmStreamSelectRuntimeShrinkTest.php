<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** stream_select() routes through VmStreamSelect poll on VmPhpFdStream fds (#9216). */
final class VmStreamSelectRuntimeShrinkTest extends TestCase
{
    public function testStreamSelectBuiltinUsesVmStreamSelectNotHostDelegation(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/stream_select.php');
        $this->assertStringContainsString('VmStreamSelect::multiplex', $source);
        $this->assertStringContainsString('ArgumentCountError', $source);
        $this->assertStringNotContainsString('VmProcess::streamSelect', $source);
        $this->assertStringNotContainsString('hostStreamsFromArray', $source);
    }

    public function testVmStreamSelectUsesPollNotHostStreamSelect(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmStreamSelect.php');
        $this->assertStringContainsString('VmPhpFdStream::fdForHandle', $source);
        $this->assertStringContainsString('poll(', $source);
        $this->assertStringContainsString('VmStreamSelectPure::multiplex', $source);
        $this->assertStringNotContainsString('@\\stream_select', $source);
    }

    public function testVmProcessDelegatesStreamSelectToVmStreamSelect(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmProcess.php');
        $this->assertStringContainsString('VmStreamSelect::multiplex', $source);
        $this->assertStringNotContainsString('@\\stream_select', $source);
    }
}
