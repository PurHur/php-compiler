<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\JIT\Variable as JitVariable;
use PHPCompiler\VM\ValueEchoJitHelper;
use PHPCompiler\VM\ValueEchoSupport;
use PHPUnit\Framework\TestCase;

/** ValueEcho JIT routes value-box dispatch through ValueEchoJitHelper PHP (#10204). */
final class ValueEchoRuntimeShrinkTest extends TestCase
{
    public function testValueEchoHelperDelegatesEmitToValueEchoRuntime(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/ValueEchoHelper.php');
        $this->assertStringContainsString('ValueEchoRuntime::emitValue', $source);
        $this->assertStringNotContainsString('echo_value_null_', $source);
        $this->assertStringNotContainsString('echo_value_after_null_', $source);
        $this->assertLessThan(140, substr_count($source, "\n") + 1);
    }

    public function testValueEchoRuntimeUsesValueEchoJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ValueEchoRuntime.php');
        $this->assertStringContainsString('ValueEchoJitHelper', $source);
        $this->assertStringContainsString('typeIsNull', $source);
        $this->assertStringContainsString('typeIsHashtable', $source);
        $this->assertStringContainsString('ensureJitHelperCompiled', $source);
        $this->assertStringContainsString('ValueEchoSupport::ARRAY_LABEL', $source);
    }

    public function testValueEchoJitHelperTypeChecks(): void
    {
        $this->assertTrue(ValueEchoJitHelper::typeIsNull(JitVariable::TYPE_NULL));
        $this->assertTrue(ValueEchoJitHelper::typeIsNativeLong(JitVariable::TYPE_NATIVE_LONG));
        $this->assertTrue(ValueEchoJitHelper::typeIsNativeBool(JitVariable::TYPE_NATIVE_BOOL));
        $this->assertTrue(ValueEchoJitHelper::typeIsNativeDouble(JitVariable::TYPE_NATIVE_DOUBLE));
        $this->assertTrue(ValueEchoJitHelper::typeIsString(JitVariable::TYPE_STRING));
        $this->assertTrue(ValueEchoJitHelper::typeIsHashtable(JitVariable::TYPE_HASHTABLE));
        $this->assertTrue(ValueEchoJitHelper::typeIsObject(JitVariable::TYPE_OBJECT));
        $this->assertFalse(ValueEchoJitHelper::typeIsNull(JitVariable::TYPE_STRING));
    }

    public function testValueEchoSupportLabels(): void
    {
        $this->assertSame('Array', ValueEchoJitHelper::arrayLabel());
        $this->assertSame('1', ValueEchoJitHelper::boolTrueLabel());
        $this->assertSame('Object', ValueEchoJitHelper::objectFallbackLabel());
        $this->assertSame(
            ValueEchoSupport::RESOURCE_FORMAT,
            ValueEchoJitHelper::resourceFormat()
        );
        $this->assertSame(
            'Object of class E could not be converted to string',
            ValueEchoSupport::objectToStringErrorMessage('E')
        );
    }
}
