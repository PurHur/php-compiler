<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Function static array subscript constant initializer (#12025, zend_compile_static_variable). */
final class FunctionStaticArrayOffsetInitTest extends TestCase
{
    public function testInlineListSubscriptInFunctionStatic(): void
    {
        $code = <<<'PHP'
<?php
function f() {
    static $x = [1, 2][0];
    return $x;
}
echo f();
PHP;
        $this->assertSame('1', $this->runVm($code));
    }

    public function testInlineAssocSubscriptInFunctionStatic(): void
    {
        $code = <<<'PHP'
<?php
function f() {
    static $x = ['a' => 1]['a'];
    return $x;
}
echo f();
PHP;
        $this->assertSame('1', $this->runVm($code));
    }

    public function testBinaryExprInFunctionStaticUnchanged(): void
    {
        $code = <<<'PHP'
<?php
function f() {
    static $x = 1 + 2;
    return $x;
}
echo f();
PHP;
        $this->assertSame('3', $this->runVm($code));
    }

    private function runVm(string $code): string
    {
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'function_static_array_offset_init.php');
        ob_start();
        $rt->run($block);

        return ob_get_clean();
    }
}
