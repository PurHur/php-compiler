<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\SessionCreateIdJitHelper;
use PHPCompiler\ext\standard\VmSession;
use PHPUnit\Framework\TestCase;

/** session_create_id JIT routes through SessionCreateIdJitHelper PHP not LLVM entropy (#9500, #21941). */
final class SessionCreateIdRuntimeShrinkTest extends TestCase
{
    public function testSessionCreateIdRuntimeUsesJitHelperNotLlvmEntropy(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/SessionCreateIdRuntime.php');
        $this->assertStringContainsString('SessionCreateIdJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('ensureStandaloneBodies', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('HEX_TABLE', $source);
        $this->assertStringNotContainsString('emitRandomIdString', $source);
        $this->assertStringNotContainsString('hexTableGlobal', $source);
        $this->assertStringNotContainsString('__compiler_random_bytes', $source);
    }

    /** Thin STANDALONE AOT must lazy-link create-id ABI (#27258; peer JitSessionStart). */
    public function testJitSessionCreateIdLazyLinksRuntimeOnInvoke(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitSessionCreateId.php');
        $this->assertStringContainsString('SessionCreateIdRuntime::ensureLinked', $source);
        $this->assertStringContainsString('BasicBlockHelper::tryGetInsertBlock', $source);
        $this->assertStringContainsString('BasicBlockHelper::restoreInsertBlock', $source);
    }

    public function testSessionCreateIdJitHelperMatchesVmSession(): void
    {
        $id = SessionCreateIdJitHelper::randomIdString();
        // php-src defaults: session.sid_length=26, sid_bits_per_character=5 (#10864).
        $this->assertSame(26, \strlen($id));
        $this->assertMatchesRegularExpression('/^[0-9a-zA-Z,-]{26}$/', $id);

        $prefixed = SessionCreateIdJitHelper::createIdNullable('app-');
        $this->assertIsString($prefixed);
        $this->assertStringStartsWith('app-', $prefixed);
        $this->assertSame('app-'.SessionCreateIdJitHelper::randomIdString(), $prefixed);

        $withPrefix = SessionCreateIdJitHelper::createIdWithPrefix('app-');
        $this->assertStringStartsWith('app-', $withPrefix);

        $vm = VmSession::createId('app-');
        $this->assertIsString($vm);
        $this->assertSame(30, \strlen($vm));
    }

    public function testSessionCreateIdJitHelperReturnsNullOnInvalidPrefix(): void
    {
        $this->assertNull(SessionCreateIdJitHelper::createIdNullable('bad prefix!'));
    }
}
