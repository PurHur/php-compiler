<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\SessionCreateIdJitHelper;
use PHPCompiler\ext\standard\VmSession;
use PHPUnit\Framework\TestCase;

/** session_create_id JIT routes through SessionCreateIdJitHelper PHP not LLVM entropy (#9500). */
final class SessionCreateIdRuntimeShrinkTest extends TestCase
{
    public function testSessionCreateIdRuntimeUsesJitHelperNotLlvmEntropy(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/SessionCreateIdRuntime.php');
        $this->assertStringContainsString('SessionCreateIdJitHelper', $source);
        $this->assertStringNotContainsString('HEX_TABLE', $source);
        $this->assertStringNotContainsString('emitRandomIdString', $source);
        $this->assertStringNotContainsString('hexTableGlobal', $source);
        $this->assertStringNotContainsString('__compiler_random_bytes', $source);
    }

    public function testSessionCreateIdJitHelperMatchesVmSession(): void
    {
        $id = SessionCreateIdJitHelper::randomIdString();
        $this->assertSame(32, \strlen($id));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $id);

        $prefixed = SessionCreateIdJitHelper::createIdNullable('app-');
        $this->assertIsString($prefixed);
        $this->assertStringStartsWith('app-', $prefixed);

        $vm = VmSession::createId('app-');
        $this->assertIsString($vm);
        $this->assertSame(36, \strlen($vm));
    }

    public function testSessionCreateIdJitHelperReturnsNullOnInvalidPrefix(): void
    {
        $this->assertNull(SessionCreateIdJitHelper::createIdNullable('bad prefix!'));
    }
}
