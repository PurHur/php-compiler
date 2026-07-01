<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\BuiltinFunctionClassConstant;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** Issue #14643 — internal function {@code ::class} (Zend/zend_compile.c). */
final class BuiltinFunctionClassConstantTest extends TestCase
{
    public function testStrlenClassConstantFoldsOnVm(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            '<?php echo strlen::class, "\n", array_map::class, "\n";',
            'fcc_builtin_class_constant.php'
        );
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        $this->assertSame("strlen\narray_map\n", $out);
    }

    public function testFunctionNameForClassOperandSkipsTypes(): void
    {
        $this->assertNull(BuiltinFunctionClassConstant::functionNameForClassOperand('int'));
        $this->assertNull(BuiltinFunctionClassConstant::functionNameForClassOperand('string'));
    }

    public function testFunctionNameForClassOperandResolvesBuiltin(): void
    {
        new Runtime();
        $this->assertSame('strlen', BuiltinFunctionClassConstant::functionNameForClassOperand('strlen'));
        $this->assertSame('array_map', BuiltinFunctionClassConstant::functionNameForClassOperand('array_map'));
    }
}
