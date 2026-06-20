<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Nullsafe JIT routes value-box short-circuit through NullsafeJitHelper PHP (#10154, #10311). */
final class NullsafeRuntimeShrinkTest extends TestCase
{
    public function testNullsafeHelperRoutesValueBoxThroughNullsafeJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/NullsafeHelper.php');
        $this->assertStringContainsString('NullsafeJitHelper', $source);
        $this->assertStringContainsString('valueBoxShortCircuits', $source);
        $this->assertStringContainsString('JitVmHelperLink', $source);
        $this->assertStringNotContainsString('NullsafeRuntime', $source);
    }

    public function testNullsafeRuntimeBridgeRemoved(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/NullsafeRuntime.php');
    }

    public function testNullsafeJitHelperAlignsWithTypedPropertyCheck(): void
    {
        $helper = \PHPCompiler\VM\NullsafeJitHelper::class;
        $this->assertTrue($helper::valueBoxShortCircuits(\PHPCompiler\VM\Variable::TYPE_NULL, false));
        $this->assertTrue($helper::valueBoxShortCircuits(
            \PHPCompiler\VM\Variable::TYPE_UNDEFINED,
            true
        ));
        $this->assertFalse($helper::valueBoxShortCircuits(
            \PHPCompiler\VM\Variable::TYPE_UNDEFINED,
            false
        ));
        $this->assertFalse($helper::valueBoxShortCircuits(
            \PHPCompiler\VM\Variable::TYPE_STRING,
            true
        ));
    }
}
