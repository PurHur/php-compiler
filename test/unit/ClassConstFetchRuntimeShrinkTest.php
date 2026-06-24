<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** ClassConstFetchHelper must delegate dynamic lowering to Builtin runtime split (#10200). */
final class ClassConstFetchRuntimeShrinkTest extends TestCase
{
    public function testHelperDelegatesToRuntimeSplit(): void
    {
        $helper = (string) file_get_contents(__DIR__.'/../../lib/JIT/ClassConstFetchHelper.php');
        $this->assertStringContainsString('ClassConstFetchRuntime::fetchDynamicByClassIdValue', $helper);
        $this->assertStringNotContainsString('class_const_dyn_try_', $helper);
        $this->assertFileExists(__DIR__.'/../../lib/JIT/Builtin/ClassConstFetchRuntime.php');
    }
}

