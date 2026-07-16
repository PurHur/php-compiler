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
        $this->assertStringContainsString('VmStreamSelectPure::multiplex', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
        $this->assertStringNotContainsString('@\\stream_select', $source);

        $pure = (string) file_get_contents(__DIR__.'/../../ext/standard/VmStreamSelectPure.php');
        $this->assertStringContainsString('VmStreamSelectPoll::multiplexFdPairs', $pure);

        $poll = (string) file_get_contents(__DIR__.'/../../ext/standard/VmStreamSelectPoll.php');
        $this->assertStringContainsString('poll(', $poll);
        $this->assertStringNotContainsString('@\\stream_select', $poll);
    }

    public function testVmProcessDelegatesStreamSelectToVmStreamSelect(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmProcess.php');
        $this->assertStringContainsString('VmStreamSelect::multiplex', $source);
        $this->assertStringNotContainsString('@\\stream_select', $source);
    }

    public function testVmStreamSelectPureIndexesReadyHostsByResourceIdNotSplObjectId(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmStreamSelectPure.php');
        $this->assertStringContainsString('get_resource_id', $source);
        $this->assertStringNotContainsString('spl_object_id', $source);
    }

    public function testPhpTempSelectPrefersFdCastOverHostTmpfile(): void
    {
        $select = (string) file_get_contents(__DIR__.'/../../ext/standard/VmStreamSelect.php');
        $this->assertStringContainsString('castFdForSelect', $select);
        $this->assertStringContainsString('ephemeralCast', $select);
        $this->assertStringContainsString('releaseEphemeralPairList', $select);

        $memory = (string) file_get_contents(__DIR__.'/../../ext/standard/VmPhpMemoryStream.php');
        $this->assertStringContainsString('castFdForSelect', $memory);
        $this->assertStringContainsString('VmPhpFdStream::openUnlinkedTempFd', $memory);

        $fd = (string) file_get_contents(__DIR__.'/../../ext/standard/VmPhpFdStream.php');
        $this->assertStringContainsString('int mkstemp(char *template);', $fd);
        $this->assertStringContainsString('openUnlinkedTempFd', $fd);
    }
}
