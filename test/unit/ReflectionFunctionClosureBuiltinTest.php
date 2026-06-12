<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** ReflectionFunction closure introspection (#6649). */
final class ReflectionFunctionClosureBuiltinTest extends TestCase
{
    public function testReflectionFunctionClosureMethods(): void
    {
        $rt = new Runtime();
        $code = <<<'PHP'
<?php
$f = function () { return 1; };
$r = new ReflectionFunction($f);
var_export($r->isClosure());
echo "\n";
var_export($r->getClosureScopeClass());
echo "\n";
$x = 42;
$g = function () use ($x) { return $x; };
var_export((new ReflectionFunction($g))->getClosureUsedVariables()['x']);
echo "\n";
PHP;
        $block = $rt->parseAndCompile($code, 'reflection_function_closure.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame("true\nNULL\n42\n", $out);
    }
}
