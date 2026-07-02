<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** array_walk() / array_walk_recursive() JIT route through ArrayWalkJitHelper PHP not ArrayBuiltinHelper LLVM (#14875, #14877, #14933). */
final class ArrayWalkRuntimeShrinkTest extends TestCase
{
    public function testArrayWalkRuntimeUsesJitHelperNotDirectLlvmMonolith(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArrayWalkRuntime.php');
        $this->assertStringContainsString('ArrayWalkJitHelper', $runtime);
        $this->assertStringContainsString('walkInPlaceWithStringBuiltin', $runtime);
        $this->assertStringContainsString('walkRecursiveInPlaceWithStringBuiltin', $runtime);
        $this->assertStringContainsString('walkInPlaceWithClosure', $runtime);
        $this->assertStringContainsString('walkRecursiveInPlaceWithClosure', $runtime);
        $this->assertStringNotContainsString('LOAD_TYPE_STANDALONE', $runtime);

        $helper = (string) file_get_contents(__DIR__.'/../../ext/standard/ArrayWalkJitHelper.php');
        $this->assertStringContainsString('walkWithClosure', $helper);
        $this->assertStringContainsString('walkRecursiveWithClosure', $helper);
        $this->assertStringContainsString('VmArrayWalk', $helper);

        $walk = (string) file_get_contents(__DIR__.'/../../ext/standard/array_walk.php');
        $this->assertStringContainsString('ArrayWalkRuntime::walkInPlaceWithStringBuiltin', $walk);
        $this->assertStringContainsString('ArrayWalkRuntime::walkInPlaceWithClosure', $walk);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::walkInPlace', $walk);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::walkInPlaceWithClosure', $walk);

        $walkRecursive = (string) file_get_contents(__DIR__.'/../../ext/standard/array_walk_recursive.php');
        $this->assertStringContainsString('ArrayWalkRuntime::walkRecursiveInPlaceWithStringBuiltin', $walkRecursive);
        $this->assertStringContainsString('ArrayWalkRuntime::walkRecursiveInPlaceWithClosure', $walkRecursive);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::walkRecursiveInPlace', $walkRecursive);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::walkRecursiveInPlaceWithClosure', $walkRecursive);

        $arrayBuiltin = (string) file_get_contents(__DIR__.'/../../lib/JIT/ArrayBuiltinHelper.php');
        $this->assertStringNotContainsString('walkInPlaceWithClosure', $arrayBuiltin);
        $this->assertStringNotContainsString('walkRecursiveInPlaceWithClosure', $arrayBuiltin);
    }
}
