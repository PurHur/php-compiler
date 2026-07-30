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
var_export((new ReflectionFunction($g))->getStaticVariables()['x']);
echo "\n";
$y = 7;
$h = function () use ($y) { static $n = 0; $n++; return $y + $n; };
$h();
$sv = (new ReflectionFunction($h))->getStaticVariables();
ksort($sv);
echo json_encode($sv);
echo "\n";
PHP;
        $block = $rt->parseAndCompile($code, 'reflection_function_closure.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame("true\nNULL\n42\n42\n{\"n\":1,\"y\":7}\n", $out);
    }
}
