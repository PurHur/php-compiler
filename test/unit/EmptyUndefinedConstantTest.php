<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #5355 — empty() on undefined bare constant */
final class EmptyUndefinedConstantTest extends TestCase
{
    public function testEmptyUndefinedBareConstantThrowsError(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            '<?php var_export(empty(UNDEFINED_CONST_XYZ));',
            'empty_undefined_constant.php'
        );
        $this->assertNotNull($block);

        $caught = null;
        try {
            $runtime->run($block);
        } catch (\Throwable $e) {
            $caught = $e;
        }
        $this->assertNotNull($caught);
        $this->assertSame('Error', $caught::class);
        $this->assertSame('Undefined constant "UNDEFINED_CONST_XYZ"', $caught->getMessage());
    }

    public function testEmptyDefinedConstantStillWorks(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            '<?php define("MY_EMPTY_CONST", 0); var_export(empty(MY_EMPTY_CONST));',
            'empty_defined_constant.php'
        );
        ob_start();
        $runtime->run($block);
        $this->assertSame('true', ob_get_clean());
    }

    public function testEmptyVariableUnchanged(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            '<?php $x = null; var_export(empty($x));',
            'empty_var.php'
        );
        ob_start();
        $runtime->run($block);
        $this->assertSame('true', ob_get_clean());
    }
}
