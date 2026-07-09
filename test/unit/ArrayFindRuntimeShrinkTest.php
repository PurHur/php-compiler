<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** array_find family closure JIT routes through ArrayFindJitHelper PHP (#17547). */
final class ArrayFindRuntimeShrinkTest extends TestCase
{
    public function testArrayFindClosureUsesNestedPhpWalk(): void
    {
        $helper = (string) file_get_contents(__DIR__.'/../../lib/JIT/ArrayFindHelper.php');
        $this->assertStringContainsString('ArrayFindRuntime::walkClosure', $helper);
        $this->assertStringContainsString('resolvePredicateHandler', $helper);

        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArrayFindRuntime.php');
        $this->assertStringContainsString('walkWithClosure', $runtime);
        $this->assertStringNotContainsString('LOAD_TYPE_STANDALONE', $runtime);

        $jitHelper = (string) file_get_contents(__DIR__.'/../../ext/standard/ArrayFindJitHelper.php');
        $this->assertStringContainsString('walkWithClosure', $jitHelper);
        $this->assertStringContainsString('VmClosureCall::invoke', $jitHelper);
    }
}
