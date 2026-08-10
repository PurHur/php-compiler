<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\VM\PropertyHookJitHelper;
use PHPCompiler\Test\Support\PropertyHookTestSkip;
use PHPUnit\Framework\TestCase;

/** PropertyHookDispatch routes guards through PropertyHookJitHelper PHP (#10112). */
final class PropertyHookRuntimeShrinkTest extends TestCase
{
        use PropertyHookTestSkip;

    protected function setUp(): void
    {
        $this->skipUnlessPropertyHooksEnabled();
    }


public function testPropertyHookDispatchIsThinTrampoline(): void
    {
        $dispatch = (string) file_get_contents(__DIR__.'/../../lib/JIT/PropertyHookDispatch.php');
        $this->assertStringContainsString('PropertyHookDispatchLlvm::', $dispatch);
        $this->assertStringContainsString('emitDimWriteRequiresByRefGetGuard', $dispatch);
        $this->assertStringNotContainsString('private static function emitGetOnlyVirtualWriteGuard', $dispatch);
        // Trampoline grows with new PropertyHookDispatchLlvm surfaces (#29748).
        $this->assertLessThan(140, substr_count($dispatch, "\n"));
    }

    public function testPropertyHookDispatchLlvmUsesPropertyHookJitHelper(): void
    {
        $llvm = (string) file_get_contents(__DIR__.'/../../lib/JIT/PropertyHookDispatchLlvm.php');
        $this->assertStringContainsString('PropertyHookJitHelper::isRawHookWrite', $llvm);
        $this->assertStringContainsString('PropertyHookJitHelper::hookedPropertyBackingName', $llvm);
        $this->assertStringContainsString('PropertyHookJitHelper::dimWriteRequiresByRefGet', $llvm);
    }

    public function testPropertyHookJitHelperHookedPropertyBackingName(): void
    {
        $registry = [
            'c' => [
                'x' => ['get' => true, 'getBacking' => '__x'],
            ],
        ];
        $this->assertSame('__x', PropertyHookJitHelper::hookedPropertyBackingName($registry, 'C', 'x'));
        $this->assertNull(PropertyHookJitHelper::hookedPropertyBackingName($registry, 'C', 'missing'));
    }

    public function testPropertyHookJitHelperDimWriteRequiresByRefGet(): void
    {
        $registry = [
            'c' => [
                'prop' => ['get' => true, 'set' => true],
                'byref' => ['get' => true, 'getByRef' => true],
            ],
        ];
        $this->assertTrue(PropertyHookJitHelper::dimWriteRequiresByRefGet($registry, 'C', 'prop'));
        $this->assertFalse(PropertyHookJitHelper::dimWriteRequiresByRefGet($registry, 'C', 'byref'));
        $this->assertFalse(PropertyHookJitHelper::dimWriteRequiresByRefGet($registry, 'C', 'missing'));
    }
}
