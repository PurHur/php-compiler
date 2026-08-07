<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** msg_* JIT routes through MsgRuntime LLVM libc (#28432). */
final class MsgRuntimeShrinkTest extends TestCase
{
    public function testMsgGetCallUsesJitMsgGet(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/sysvmsg/msg_get_queue.php');
        $this->assertStringContainsString('JitMsgGet::invoke', $source);
        $this->assertStringNotContainsString('not supported for JIT/AOT', $source);
    }

    public function testMsgSendCallUsesJitMsgSend(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/sysvmsg/msg_send.php');
        $this->assertStringContainsString('JitMsgSend::invoke', $source);
        $this->assertStringNotContainsString('not supported for JIT/AOT', $source);
    }

    public function testMsgReceiveCallUsesJitMsgReceive(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/sysvmsg/msg_receive.php');
        $this->assertStringContainsString('JitMsgReceive::invoke', $source);
        $this->assertStringNotContainsString('not supported for JIT/AOT', $source);
    }

    public function testMsgRemoveCallUsesJitMsgRemove(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/sysvmsg/msg_remove_queue.php');
        $this->assertStringContainsString('JitMsgRemove::invoke', $source);
        $this->assertStringNotContainsString('not supported for JIT/AOT', $source);
    }

    public function testMsgRuntimeIsPureLlvmNoNestedJitMap(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MsgRuntime.php');
        $this->assertStringContainsString('__compiler_msg_get_register', $source);
        $this->assertStringContainsString('msgget', $source);
        $this->assertStringContainsString('msgsnd', $source);
        $this->assertStringContainsString('msgrcv', $source);
        $this->assertStringContainsString('__compiler_msg_owned_map', $source);
        $this->assertStringNotContainsString('JitVmHelperLink', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
    }

    public function testSpineBundleIncludesMsgHelpers(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('MsgJitHelper.php', $spine);
        $this->assertStringContainsString('MsgLibcThinAbi.php', $spine);
        $this->assertStringContainsString('JitMsgGet.php', $spine);
        $this->assertStringContainsString('MsgRuntime.php', $spine);
        $this->assertStringContainsString('StringMsg.php', $spine);
    }
}
