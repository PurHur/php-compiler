<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\SplAutoloadJitHelper;
use PHPUnit\Framework\TestCase;

/** SplAutoloadOutput routes stack through SplAutoloadJitHelper PHP not LLVM globals (#9238). */
final class SplAutoloadOutputShrinkTest extends TestCase
{
    protected function tearDown(): void
    {
        SplAutoloadJitHelper::resetForTests();
        parent::tearDown();
    }

    public function testSplAutoloadOutputUsesJitHelperNotLlvmGlobals(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/SplAutoloadOutput.php');
        $this->assertStringContainsString('SplAutoloadJitHelper', $source);
        $this->assertStringNotContainsString('GLOBAL_STACK', $source);
        $this->assertStringNotContainsString('GLOBAL_DEPTH', $source);
        $this->assertStringNotContainsString('GLOBAL_META_STACK', $source);
        $this->assertStringNotContainsString('emitRegisterApply', $source);
        $this->assertLessThan(320, \substr_count($source, "\n") + 1);
    }

    public function testSplAutoloadJitHelperPrependAppendOrder(): void
    {
        SplAutoloadJitHelper::registerApply(10, 100, false);
        SplAutoloadJitHelper::registerApply(20, 200, true);
        $this->assertSame(2, SplAutoloadJitHelper::depth());
        $this->assertSame(20, SplAutoloadJitHelper::fnOpaqueAt(0));
        $this->assertSame(10, SplAutoloadJitHelper::fnOpaqueAt(1));
        $this->assertSame(200, SplAutoloadJitHelper::metaOpaqueAt(0));
    }

    public function testSplAutoloadJitHelperUnregister(): void
    {
        SplAutoloadJitHelper::registerApply(42, 1, false);
        $this->assertTrue(SplAutoloadJitHelper::unregisterApply(42));
        $this->assertSame(0, SplAutoloadJitHelper::depth());
        $this->assertFalse(SplAutoloadJitHelper::unregisterApply(42));
    }
}
