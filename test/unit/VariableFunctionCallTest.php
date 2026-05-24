<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Variable function calls ($fn()) — issue #56. */
final class VariableFunctionCallTest extends TestCase
{
    public function testVmBuiltinVariableFunctionCall(): void
    {
        $code = <<<'PHP'
<?php
$fn = 'strlen';
echo $fn('abc');
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'test.php');
        ob_start();
        $rt->run($block);
        $this->assertSame('3', ob_get_clean());
    }

    public function testVmUndefinedVariableFunctionThrows(): void
    {
        $code = <<<'PHP'
<?php
$fn = 'not_a_real_function_xyz';
$fn();
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'test.php');
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Call to undefined function not_a_real_function_xyz()');
        $rt->run($block);
    }
}
