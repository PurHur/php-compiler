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
class Base3159 { public $inherited = 9; }
class Child3159 extends Base3159 { public $a = 1; private $b = 2; }
$p = class_parents(Child3159::class);
echo count($p), "\n", $p['Base3159'], "\n";
$v = get_class_vars(Child3159::class);
echo count($v), "\n", $v['a'], "\n", $v['inherited'], "\n";
echo isset($v['b']) ? 'has-b' : 'no-b';
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'class_parents_get_class_vars.php');
        ob_start();
        $rt->run($block);
        $this->assertSame("1\nBase3159\n2\n1\n9\nno-b", ob_get_clean());
    }

    public function testVmClassParentsAutoloadFlag(): void
    {
        $code = <<<'PHP'
<?php
class Base5026 {}
class Child5026 extends Base5026 {}
$p = class_parents(Child5026::class, true);
echo count($p), "\n", $p['Base5026'], "\n";
echo class_parents(Child5026::class, false)['Base5026'], "\n";
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'class_parents_autoload.php');
        ob_start();
        $rt->run($block);
        $this->assertSame("1\nBase5026\nBase5026\n", ob_get_clean());
    }
}
