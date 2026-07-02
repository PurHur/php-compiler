<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** array_walk() JIT routes string-builtin through ArrayWalkJitHelper PHP not ArrayBuiltinHelper LLVM (#14875). */
final class ArrayWalkRuntimeShrinkTest extends TestCase
{
    public function testArrayWalkRuntimeUsesJitHelperNotDirectLlvmMonolith(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArrayWalkRuntime.php');
        $this->assertStringContainsString('ArrayWalkJitHelper', $runtime);
        $this->assertStringContainsString('walkInPlaceWithStringBuiltin', $runtime);
        $this->assertStringNotContainsString('LOAD_TYPE_STANDALONE', $runtime);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/array_walk.php');
        $this->assertStringContainsString('ArrayWalkRuntime::walkInPlaceWithStringBuiltin', $builtin);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::walkInPlace(', $builtin);
    }
}
