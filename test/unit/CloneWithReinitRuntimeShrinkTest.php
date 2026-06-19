<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\CloneWithJitHelper;
use PHPUnit\Framework\TestCase;

/** Clone-with reinit JIT/AOT bridge uses PHP SSOT (#9498, #9717). */
final class CloneWithReinitRuntimeShrinkTest extends TestCase
{
    public function testCloneWithReinitRuntimeUsesJitHelperOnly(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/CloneWithReinitRuntime.php');
        $this->assertStringContainsString('CloneWithJitHelper', $source);
        $this->assertStringNotContainsString('CloneWithReinitRuntimeLlvm', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/CloneWithReinitRuntimeLlvm.php');
    }

    public function testCloneWithJitHelperReinitLifecycle(): void
    {
        CloneWithJitHelper::resetForTest();
        CloneWithJitHelper::begin(100);
        CloneWithJitHelper::addProperty('x');
        CloneWithJitHelper::addProperty('y');
        $this->assertTrue(CloneWithJitHelper::tryConsume(100, 'x'));
        $this->assertTrue(CloneWithJitHelper::tryConsume(100, 'y'));
        $this->assertFalse(CloneWithJitHelper::tryConsume(100, 'x'));
        $this->assertFalse(CloneWithJitHelper::tryConsume(99, 'x'));
        CloneWithJitHelper::end(100);
        $this->assertFalse(CloneWithJitHelper::tryConsume(100, 'z'));
    }
}
