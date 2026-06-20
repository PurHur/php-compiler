<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\JIT\Variable as JitVariable;
use PHPCompiler\VM\InOperatorJitHelper;
use PHPCompiler\VM\Variable as VmVariable;
use PHPUnit\Framework\TestCase;

/** InOperator JIT routes value-box guard through InOperatorJitHelper PHP (#10172, #10342). */
final class InOperatorRuntimeShrinkTest extends TestCase
{
    public function testInOperatorHelperRoutesValueBoxThroughInOperatorJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/InOperatorHelper.php');
        $this->assertStringContainsString('InOperatorJitHelper', $source);
        $this->assertStringContainsString('valueBoxHaystackIsArray', $source);
        $this->assertStringContainsString('JitVmHelperLink', $source);
        $this->assertStringNotContainsString('InOperatorRuntime', $source);
    }

    public function testInOperatorRuntimeBridgeRemoved(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/InOperatorRuntime.php');
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
