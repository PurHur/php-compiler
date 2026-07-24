<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Spaceship scalar JIT routes through CompareJitHelperScalars NestedJIT; object/ht via LLVM (#9381, #21109). */
final class SpaceshipCompareJitHelperTest extends TestCase
{
    public function testCompareJitHelperDelegatesScalarSemantics(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/VM/CompareJitHelper.php');
        $this->assertStringContainsString('CompareJitHelperScalars', $source);
        $this->assertStringContainsString('objectSpaceship', $source);
        $this->assertStringContainsString('hashtableSpaceship', $source);
    }

    public function testSpaceshipRuntimeCompilesScalarHelpersOnly(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/SpaceshipRuntime.php');
        $this->assertStringContainsString('CompareJitHelperScalars', $source);
        $this->assertStringNotContainsString('OBJECT_HELPER', $source);
        $this->assertStringNotContainsString('HASHTABLE_HELPER', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope', $source);
    }

    public function testSpaceshipCompareKernelRoutesScalarsThroughHelper(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/standard/JitSpaceshipCompareKernel.php');
        $this->assertStringContainsString('CompareJitHelperScalars::longSpaceship', $source);
        $this->assertStringContainsString('CompareJitHelperScalars::stringSpaceship', $source);
        $this->assertStringContainsString('emitObjectCompareSpaceship(', $source);
        $this->assertStringContainsString('emitHashtableCompareSpaceship(', $source);
        $this->assertStringNotContainsString('emitObjectCompareSpaceshipBridge', $source);
        $this->assertStringNotContainsString('emitHashtableCompareSpaceshipBridge', $source);
        $this->assertStringContainsString('__object__prop_count', $source);
        $this->assertStringNotContainsString('JitFloatCompare', $source);
        $this->assertStringNotContainsString('stringIsNumeric', $source);
    }

    public function testCompareJitHelperObjectAndHashtableSemantics(): void
    {
        $enumClass = new \PHPCompiler\VM\ClassEntry('E');
        $enumClass->isEnum = true;
        $enumClass->backedType = 'int';
        $backingA = new \PHPCompiler\VM\Variable();
        $backingA->int(1);
        $backingB = new \PHPCompiler\VM\Variable();
        $backingB->int(2);
        $left = \PHPCompiler\VM\EnumCaseSupport::createCase($enumClass, 'A', $backingA)->toObject();
        $right = \PHPCompiler\VM\EnumCaseSupport::createCase($enumClass, 'B', $backingB)->toObject();
        $this->assertSame(1, \PHPCompiler\VM\CompareJitHelper::objectSpaceship($left, $right));

        $htLeft = new \PHPCompiler\VM\HashTable();
        $v1 = new \PHPCompiler\VM\Variable();
        $v1->int(1);
        $htLeft->add('a', $v1);
        $htRight = new \PHPCompiler\VM\HashTable();
        $v2 = new \PHPCompiler\VM\Variable();
        $v2->int(2);
        $htRight->add('a', $v2);
        $this->assertSame(-1, \PHPCompiler\VM\CompareJitHelper::hashtableSpaceship($htLeft, $htRight));
    }

    public function testCompareJitHelperScalarSemantics(): void
    {
        $this->assertSame(-1, \PHPCompiler\VM\CompareJitHelper::longSpaceship(1, 2));
        $this->assertSame(-1, \PHPCompiler\VM\CompareJitHelper::stringSpaceship('a', 'b'));
        // Zend zendi_smart_strcmp (#22848): both numeric → numeric order.
        $this->assertSame(1, \PHPCompiler\VM\CompareJitHelper::stringSpaceship('10', '2'));
        $this->assertSame(-1, \PHPCompiler\VM\CompareJitHelper::stringSpaceship('2', '10'));
        $this->assertSame(0, \PHPCompiler\VM\CompareJitHelper::stringSpaceship('1e1', '10'));
        $this->assertSame(0, \PHPCompiler\VM\CompareJitHelper::stringSpaceship('0', '00'));
        $this->assertSame(-1, \PHPCompiler\VM\CompareJitHelper::spaceshipNumberString(1.0, 'b', 1));
        $this->assertSame(0, \PHPCompiler\VM\CompareJitHelper::spaceshipNumberString(1.0, '1', 1));
        $this->assertSame(-1, \PHPCompiler\VM\CompareJitHelperScalars::longSpaceship(1, 2));
    }
}
