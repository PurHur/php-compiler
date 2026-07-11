<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Function static literal array subscript in constant expressions (#12025, zend_compile.c). */
final class FunctionStaticArrayOffsetInitTest extends TestCase
{
    public function testListOffsetInitializer(): void
    {
        $code = <<<'PHP'
<?php
function f(): int
{
    static $x = [1, 2][0];

    return $x;
}
echo f();
PHP;
        $this->assertSame('1', $this->runVm($code));
    }

    public function testAssocOffsetInitializer(): void
    {
        $code = <<<'PHP'
<?php
function f(): int
{
    static $x = ['a' => 1]['a'];

    return $x;
}
echo f();
PHP;
        $this->assertSame('1', $this->runVm($code));
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
