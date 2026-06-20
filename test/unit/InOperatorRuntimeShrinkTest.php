<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\JIT\Variable as JitVariable;
use PHPCompiler\VM\InOperatorJitHelper;
use PHPCompiler\VM\Variable as VmVariable;
use PHPUnit\Framework\TestCase;

/** InOperator JIT routes value-box guard through InOperatorJitHelper PHP (#10172). */
final class InOperatorRuntimeShrinkTest extends TestCase
{
    public function testInOperatorRuntimeUsesInOperatorJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/InOperatorRuntime.php');
        $this->assertStringContainsString('InOperatorJitHelper', $source);
        $this->assertStringContainsString('valueBoxHaystackIsArray', $source);
    }

    public function testInOperatorHelperRoutesValueBoxGuardThroughInOperatorRuntime(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/InOperatorHelper.php');
        $this->assertStringContainsString('InOperatorRuntime', $source);
        $this->assertStringNotContainsString('guardHaystackIsArray', $source);
        $this->assertLessThanOrEqual(35, substr_count($source, "\n") + 1);
    }

    public function testInOperatorJitHelperValueBoxHaystackIsArray(): void
    {
        $this->assertTrue(InOperatorJitHelper::valueBoxHaystackIsArray(VmVariable::TYPE_ARRAY));
        $this->assertTrue(InOperatorJitHelper::valueBoxHaystackIsArray(JitVariable::TYPE_HASHTABLE));
        $this->assertFalse(InOperatorJitHelper::valueBoxHaystackIsArray(VmVariable::TYPE_NULL));
        $this->assertFalse(InOperatorJitHelper::valueBoxHaystackIsArray(VmVariable::TYPE_STRING));
    }

    public function testInOperatorJitHelperAlignsWithVmInOperatorLabels(): void
    {
        $this->assertSame('int', InOperatorJitHelper::vmOperandLabel(VmVariable::TYPE_INTEGER));
        $this->assertSame('array', InOperatorJitHelper::vmOperandLabel(VmVariable::TYPE_ARRAY));
        $this->assertSame('enum', InOperatorJitHelper::vmOperandLabel(VmVariable::TYPE_ENUM_CASE));
    }
}
