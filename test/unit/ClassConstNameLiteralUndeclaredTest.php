<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * X::class is a pure name literal — Zend resolves it without the class being
 * declared (zend_compile.c ZEND_FETCH_CLASS_NAME). Native 8.3+ exception names
 * (DateException, …) hit this on the 8.2 reference profile (#16828).
 */
final class ClassConstNameLiteralUndeclaredTest extends TestCase
{
    public function testUndeclaredClassNameLiteralInConstExpr(): void
    {
        $code = <<<'PHP'
<?php
class M {
    public const MAP = [
        'a' => \TotallyUndeclaredClass98::class,
        'b' => \DateException::class,
    ];
}
echo M::MAP['a'], ' ', M::MAP['b'];
PHP;
        $this->assertSame('TotallyUndeclaredClass98 DateException', $this->runVm($code));
    }

    public function testUndeclaredClassNameLiteralAsExpression(): void
    {
        $code = <<<'PHP'
<?php
echo \SomeNeverDeclaredThing::class;
PHP;
        $this->assertSame('SomeNeverDeclaredThing', $this->runVm($code));
    }

    private function runVm(string $code): string
    {
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'class_const_name_literal_undeclared.php');
        ob_start();
        $rt->run($block);

        return ob_get_clean();
    }
}
