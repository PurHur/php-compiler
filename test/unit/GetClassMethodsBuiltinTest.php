<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** get_class_methods() VM builtin (issue #3118). */
final class GetClassMethodsBuiltinTest extends TestCase
{
    public function testVmGetClassMethodsDirectAndInherited(): void
    {
        $code = <<<'PHP'
<?php
class Base {
    public function parentMethod(): void {}
}
class Child extends Base {
    public function childMethod(): void {}
}
$methods = get_class_methods('Child');
sort($methods);
echo count($methods), "\n";
echo in_array('parentMethod', $methods, true) ? '1' : '0';
echo in_array('childMethod', $methods, true) ? '1' : '0';
try {
    get_class_methods('Missing');
    echo '0';
} catch (TypeError $e) {
    echo '1';
}
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'get_class_methods.php');
        ob_start();
        $rt->run($block);
        $this->assertSame("2\n111", ob_get_clean());
    }

    public function testVmGetClassMethodsInterfaceAndAbstract(): void
    {
        $code = <<<'PHP'
<?php
interface I {
    public function m(): void;
}
$methods = get_class_methods(I::class);
sort($methods);
echo count($methods), "\n";
echo in_array('m', $methods, true) ? '1' : '0';
echo method_exists(I::class, 'm') ? '1' : '0';
abstract class A {
    abstract public function m(): void;
}
echo method_exists(A::class, 'm') ? '1' : '0';
class_alias(I::class, 'IAlias');
echo method_exists('IAlias', 'm') ? '1' : '0';
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'class_methods_interface.php');
        ob_start();
        $rt->run($block);
        $this->assertSame("1\n1111", ob_get_clean());
    }

    public function testVmGetClassMethodsRespectsCallingScope(): void
    {
        $code = <<<'PHP'
<?php
class A {
    private function p() {}
    protected function r() {}
    public function u() {}
    public function t() { return get_class_methods($this); }
}
class B extends A {
    public function t2() { return get_class_methods($this); }
}
echo 'out=', implode(',', get_class_methods('A')), "\n";
echo 'in=', implode(',', (new A)->t()), "\n";
echo 'child=', implode(',', (new B)->t2()), "\n";
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'get_class_methods_scope.php');
        ob_start();
        $rt->run($block);
        $this->assertSame("out=u,t\nin=p,r,u,t\nchild=t2,r,u,t\n", ob_get_clean());
    }
}
