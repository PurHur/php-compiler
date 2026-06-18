<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\CloneWithJitHelper;
use PHPUnit\Framework\TestCase;

/** Clone-with reinit JIT bridge uses PHP SSOT (#9498). */
final class CloneWithReinitRuntimeShrinkTest extends TestCase
{
    public function testCloneWithReinitRuntimeDelegatesToJitHelperOnJitPath(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/CloneWithReinitRuntime.php');
        $this->assertStringContainsString('CloneWithJitHelper', $source);
        $this->assertStringContainsString('CloneWithReinitRuntimeLlvm', $source);
        $this->assertStringContainsString('LOAD_TYPE_STANDALONE', $source);
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
