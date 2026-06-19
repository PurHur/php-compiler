<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Nullsafe JIT routes value-box short-circuit through NullsafeJitHelper PHP (#10154). */
final class NullsafeRuntimeShrinkTest extends TestCase
{
    public function testNullsafeRuntimeUsesNullsafeJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/NullsafeRuntime.php');
        $this->assertStringContainsString('NullsafeJitHelper', $source);
        $this->assertStringContainsString('valueBoxShortCircuits', $source);
    }

    public function testNullsafeHelperRoutesValueBoxThroughNullsafeRuntime(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/NullsafeHelper.php');
        $this->assertStringContainsString('NullsafeRuntime', $source);
        $this->assertStringNotContainsString('isNullableUninitializedProperty', $source);
        $this->assertLessThanOrEqual(85, substr_count($source, "\n") + 1);
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
