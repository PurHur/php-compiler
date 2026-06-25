<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Spaceship scalar JIT routes through CompareJitHelper PHP, not hand-written LLVM (#9381). */
final class SpaceshipCompareJitHelperTest extends TestCase
{
    public function testCompareJitHelperDelegatesScalarSemantics(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/VM/CompareJitHelper.php');
        $this->assertStringContainsString('spaceshipNumeric', $source);
        $this->assertStringContainsString('objectSpaceship', $source);
        $this->assertStringContainsString('hashtableSpaceship', $source);
    }

    public function testSpaceshipRuntimeCompilesCompareJitHelper(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/SpaceshipRuntime.php');
        $this->assertStringContainsString('CompareJitHelper', $source);
        $this->assertStringContainsString('OBJECT_HELPER', $source);
        $this->assertStringContainsString('HASHTABLE_HELPER', $source);
        $this->assertStringContainsString('NestedJitCompileScope', $source);
    }

    public function testSpaceshipCompareJitRoutesScalarsThroughHelper(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/SpaceshipCompareJit.php');
        $this->assertStringContainsString('CompareJitHelper::longSpaceship', $source);
        $this->assertStringContainsString('CompareJitHelper::stringSpaceship', $source);
        $this->assertStringContainsString('CompareJitHelper::objectSpaceship', $source);
        $this->assertStringContainsString('CompareJitHelper::hashtableSpaceship', $source);
        $this->assertStringContainsString('emitObjectCompareSpaceshipBridge', $source);
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
        $this->assertSame(-1, \PHPCompiler\VM\CompareJitHelper::spaceshipNumberString(1.0, 'b', 1));
        $this->assertSame(0, \PHPCompiler\VM\CompareJitHelper::spaceshipNumberString(1.0, '1', 1));
    }
}
