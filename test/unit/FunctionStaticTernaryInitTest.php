<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Function static ternary constant-expression initializer (#12026, zend_compile.c). */
final class FunctionStaticTernaryInitTest extends TestCase
{
    public function testTrueArmInitializer(): void
    {
        $code = <<<'PHP'
<?php
function f(): int
{
    static $x = true ? 1 : 2;

    return $x;
}
echo f();
echo "\n";
echo f();
PHP;
        $this->assertSame("1\n1", $this->runVm($code));
    }

    public function testFalseArmInitializer(): void
    {
        $code = <<<'PHP'
<?php
function f(): int
{
    static $x = false ? 1 : 2;

    return $x;
}
echo f();
PHP;
        $this->assertSame('2', $this->runVm($code));
    }

    private function runVm(string $code): string
    {
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'function_static_ternary_init.php');
        ob_start();
        $rt->run($block);

        return ob_get_clean();
    }
}
