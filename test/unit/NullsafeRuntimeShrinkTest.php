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
        $this->assertStringContainsString('valueBoxMethodShortCircuits', $source);
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
        $this->assertTrue($helper::valueBoxShortCircuits(
            \PHPCompiler\VM\Variable::TYPE_INTEGER,
            false
        ));
        $this->assertTrue($helper::valueBoxShortCircuits(
            \PHPCompiler\VM\Variable::TYPE_STRING,
            false
        ));
        $this->assertFalse($helper::valueBoxShortCircuits(
            \PHPCompiler\VM\Variable::TYPE_OBJECT,
            false
        ));
        $this->assertTrue($helper::valueBoxMethodShortCircuits(
            \PHPCompiler\VM\Variable::TYPE_NULL,
            false
        ));
        $this->assertFalse($helper::valueBoxMethodShortCircuits(
            \PHPCompiler\VM\Variable::TYPE_INTEGER,
            false
        ));
        $this->assertFalse($helper::valueBoxMethodShortCircuits(
            \PHPCompiler\VM\Variable::TYPE_STRING,
            false
        ));
        $this->assertTrue($helper::valueBoxMethodShortCircuits(
            \PHPCompiler\VM\Variable::TYPE_UNDEFINED,
            true
        ));
    }

    public function testNullsafeShortCircuitReceiverSkipsScalar(): void
    {
        $int = new \PHPCompiler\VM\Variable();
        $int->int(1);
        $this->assertTrue(
            \PHPCompiler\VM\TypedPropertyCheck::nullsafeShortCircuitReceiver($int)
        );
        $this->assertFalse(
            \PHPCompiler\VM\TypedPropertyCheck::nullsafeShortCircuitReceiver($int, true)
        );
    }

    public function testNullsafeShortCircuitReceiverKeepsObject(): void
    {
        $obj = new \PHPCompiler\VM\Variable();
        $obj->object(new \PHPCompiler\VM\ObjectEntry(new \PHPCompiler\VM\ClassEntry('C')));
        $this->assertFalse(
            \PHPCompiler\VM\TypedPropertyCheck::nullsafeShortCircuitReceiver($obj)
        );
        $this->assertFalse(
            \PHPCompiler\VM\TypedPropertyCheck::nullsafeShortCircuitReceiver($obj, true)
        );
    }

    public function testNullsafeShortCircuitMethodReceiverOnlyNull(): void
    {
        $null = new \PHPCompiler\VM\Variable();
        $null->null();
        $this->assertTrue(
            \PHPCompiler\VM\TypedPropertyCheck::nullsafeShortCircuitReceiver($null, true)
        );
    }
}
