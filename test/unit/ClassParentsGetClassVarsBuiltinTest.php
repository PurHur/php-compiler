<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** class_parents() / get_class_vars() VM builtins (issue #3159). */
final class ClassParentsGetClassVarsBuiltinTest extends TestCase
{
    public function testVmClassParentsAndGetClassVars(): void
    {
        $code = <<<'PHP'
<?php
class Base3159 {}
class Child3159 extends Base3159 { public $a = 1; private $b = 2; }
$p = class_parents(Child3159::class);
echo count($p), "\n", $p[0], "\n";
$v = get_class_vars(Child3159::class);
echo count($v), "\n", $v['a'], "\n";
echo isset($v['b']) ? 'has-b' : 'no-b';
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'class_parents_get_class_vars.php');
        ob_start();
        $rt->run($block);
        $this->assertSame("1\nBase3159\n1\n1\nno-b", ob_get_clean());
    }
}
