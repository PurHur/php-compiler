<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\JIT\Variable as JitVariable;
use PHPCompiler\VM\ClosureBindJitHelper;
use PHPCompiler\VM\Variable as VmVariable;
use PHPUnit\Framework\TestCase;

/** Closure bind JIT routes guards through ClosureBindJitHelper PHP (#10109). */
final class ClosureBindRuntimeShrinkTest extends TestCase
{
    public function testClosureBindHelperRoutesValueBoxThroughClosureBindJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/ClosureBindHelper.php');
        $this->assertStringContainsString('ClosureBindJitHelper', $source);
        $this->assertStringContainsString('ClosureBindRuntime', $source);
        $this->assertStringNotContainsString('scalarLabel', $source);
        $this->assertStringNotContainsString('private static function thisArgLabel', $source);
    }

    public function testClosureBindRuntimeLinksVmHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ClosureBindRuntime.php');
        $this->assertStringContainsString('JitVmHelperLink', $source);
        $this->assertStringContainsString('ClosureBindJitHelper', $source);
    }

    public function testClosureBindJitHelperInvalidNullableObjectTypes(): void
    {
        $this->assertTrue(ClosureBindJitHelper::jitTypeIsInvalidNullableObject(JitVariable::TYPE_NATIVE_LONG));
        $this->assertTrue(ClosureBindJitHelper::jitTypeIsInvalidNullableObject(JitVariable::TYPE_STRING));
        $this->assertFalse(ClosureBindJitHelper::jitTypeIsInvalidNullableObject(JitVariable::TYPE_NULL));
        $this->assertFalse(ClosureBindJitHelper::jitTypeIsInvalidNullableObject(JitVariable::TYPE_OBJECT));
        $this->assertFalse(ClosureBindJitHelper::jitTypeIsInvalidNullableObject(JitVariable::TYPE_VALUE));
    }

    public function testClosureBindJitHelperValueBoxKinds(): void
    {
        $this->assertSame(
            ClosureBindJitHelper::KIND_NULL,
            ClosureBindJitHelper::valueBoxKindForNullableObject(VmVariable::TYPE_NULL)
        );
        $this->assertSame(
            ClosureBindJitHelper::KIND_OBJECT,
            ClosureBindJitHelper::valueBoxKindForNullableObject(VmVariable::TYPE_OBJECT)
        );
        $this->assertSame(
            ClosureBindJitHelper::KIND_INVALID,
            ClosureBindJitHelper::valueBoxKindForNullableObject(VmVariable::TYPE_INTEGER)
        );
        $this->assertSame(
            ClosureBindJitHelper::KIND_STRING,
            ClosureBindJitHelper::valueBoxKindForNullableObjectOrString(VmVariable::TYPE_STRING)
        );
    }

    public function testClosureBindJitHelperStaticScopeAlias(): void
    {
        $this->assertTrue(ClosureBindJitHelper::resolveStaticScopeAlias('static'));
        $this->assertTrue(ClosureBindJitHelper::resolveStaticScopeAlias('Static'));
        $this->assertFalse(ClosureBindJitHelper::resolveStaticScopeAlias('self'));
    }

    public function testClosureBindHelperLineCountReduced(): void
    {
        $lines = \substr_count((string) file_get_contents(__DIR__.'/../../lib/JIT/ClosureBindHelper.php'), "\n") + 1;
        $this->assertLessThan(645, $lines);
    }
}
