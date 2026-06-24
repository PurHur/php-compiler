<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\VM\MagicMethodJitHelper;
use PHPUnit\Framework\TestCase;

/** MagicMethodDispatch routes compile guards through MagicMethodJitHelper PHP (#10201). */
final class MagicMethodRuntimeShrinkTest extends TestCase
{
    public function testMagicMethodDispatchIsThinTrampoline(): void
    {
        $dispatch = (string) file_get_contents(__DIR__.'/../../lib/JIT/MagicMethodDispatch.php');
        $this->assertStringContainsString('MagicMethodLlvm::', $dispatch);
        $this->assertStringNotContainsString('private static function packPositionalArgs', $dispatch);
        $this->assertLessThan(120, substr_count($dispatch, "\n"));
    }

    public function testMagicMethodLlvmUsesMagicMethodJitHelper(): void
    {
        $llvm = (string) file_get_contents(__DIR__.'/../../lib/JIT/MagicMethodLlvm.php');
        $this->assertStringContainsString('MagicMethodJitHelper::propertyReadUsesMagicGet', $llvm);
    }

    public function testMagicMethodJitHelperPropertyReadUsesMagicGet(): void
    {
        $this->assertFalse(MagicMethodJitHelper::propertyReadUsesMagicGet(false, true, false, false));
        $this->assertTrue(MagicMethodJitHelper::propertyReadUsesMagicGet(true, false, false, false));
        $this->assertFalse(MagicMethodJitHelper::propertyReadUsesMagicGet(true, true, true, false));
        $this->assertTrue(MagicMethodJitHelper::propertyReadUsesMagicGet(true, true, false, true));
        $this->assertFalse(MagicMethodJitHelper::propertyReadUsesMagicGet(true, true, false, false));
    }
}
