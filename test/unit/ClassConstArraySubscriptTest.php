<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Class constant array subscript in scalar expressions (#5465, zend_compile.c). */
final class ClassConstArraySubscriptTest extends TestCase
{
    public function testSelfArraySubscriptInClassConst(): void
    {
        $code = <<<'PHP'
<?php
class C {
    public const ARR = [10, 20, 30];
    public const X = self::ARR[1];
}
echo C::X;
PHP;
        $this->assertSame('20', $this->runVm($code));
    }

    public function testClassNameArraySubscriptInClassConst(): void
    {
        $code = <<<'PHP'
<?php
class C {
    public const ARR = [10, 20, 30];
    public const Y = C::ARR[2];
}
echo C::Y;
PHP;
        $this->assertSame('30', $this->runVm($code));
    }

    public function testInlineArrayLiteralSubscriptInClassConst(): void
    {
        $code = <<<'PHP'
<?php
class C {
    public const X = [1, 2][0];
}
echo C::X;
PHP;
        $this->assertSame('1', $this->runVm($code));
    }

    private function runVm(string $code): string
    {
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'class_const_array_subscript.php');
        ob_start();
        $rt->run($block);

        return ob_get_clean();
    }
}
