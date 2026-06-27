<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\PropertyHookProfileSkipTrait;
use PHPCompiler\VM\PropertyHookJitHelper;
use PHPUnit\Framework\TestCase;

/** PropertyHookDispatch routes guards through PropertyHookJitHelper PHP (#10112). */
final class PropertyHookRuntimeShrinkTest extends TestCase
{
    use PropertyHookProfileSkipTrait;

    protected function setUp(): void
    {
        $this->skipUnlessPropertyHooks();
    }

    public function testPropertyHookDispatchIsThinTrampoline(): void
    {
        $dispatch = (string) file_get_contents(__DIR__.'/../../lib/JIT/PropertyHookDispatch.php');
        $this->assertStringContainsString('PropertyHookDispatchLlvm::', $dispatch);
        $this->assertStringNotContainsString('private static function emitGetOnlyVirtualWriteGuard', $dispatch);
        $this->assertLessThan(110, substr_count($dispatch, "\n"));
    }

    public function testPropertyHookDispatchLlvmUsesPropertyHookJitHelper(): void
    {
        $llvm = (string) file_get_contents(__DIR__.'/../../lib/JIT/PropertyHookDispatchLlvm.php');
        $this->assertStringContainsString('PropertyHookJitHelper::isRawHookWrite', $llvm);
        $this->assertStringContainsString('PropertyHookJitHelper::hookedPropertyBackingName', $llvm);
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
}
