<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** array_walk() / array_walk_recursive() JIT route string-builtin through ArrayWalkJitHelper PHP not ArrayBuiltinHelper LLVM (#14875, #14877). */
final class ArrayWalkRuntimeShrinkTest extends TestCase
{
    public function testArrayWalkRuntimeUsesJitHelperNotDirectLlvmMonolith(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArrayWalkRuntime.php');
        $this->assertStringContainsString('ArrayWalkJitHelper', $runtime);
        $this->assertStringContainsString('walkInPlaceWithStringBuiltin', $runtime);
        $this->assertStringContainsString('walkRecursiveInPlaceWithStringBuiltin', $runtime);
        $this->assertStringNotContainsString('LOAD_TYPE_STANDALONE', $runtime);

        $walk = (string) file_get_contents(__DIR__.'/../../ext/standard/array_walk.php');
        $this->assertStringContainsString('ArrayWalkRuntime::walkInPlaceWithStringBuiltin', $walk);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::walkInPlace(', $walk);

        $walkRecursive = (string) file_get_contents(__DIR__.'/../../ext/standard/array_walk_recursive.php');
        $this->assertStringContainsString('ArrayWalkRuntime::walkRecursiveInPlaceWithStringBuiltin', $walkRecursive);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::walkRecursiveInPlace(', $walkRecursive);
    }
}
