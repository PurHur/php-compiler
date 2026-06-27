<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Test\Support\PropertyHookTestSkip;
use PHPUnit\Framework\TestCase;

/** class_parents() / get_class_vars() VM builtins (issue #3159). */
final class ClassParentsGetClassVarsBuiltinTest extends TestCase
{
    use PropertyHookTestSkip;

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

    public function testVmClassParentsEnumCase(): void
    {
        $code = <<<'PHP'
<?php
enum Enum6336 { case A; case B; }
var_export(class_parents(Enum6336::A));
echo "\n";
var_export(class_parents(Enum6336::B));
echo "\n";
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'class_parents_enum_case.php');
        ob_start();
        $rt->run($block);
        $this->assertSame("array (\n)\narray (\n)\n", ob_get_clean());
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

    public function testVmGetClassVarsTraitInterfaceEnum(): void
    {
        $code = <<<'PHP'
<?php
trait T7397 { public static string $s = 'hi'; public int $y = 2; }
interface I7397 { public const C = 1; }
enum E7397: string { case A = 'a'; }
$t = get_class_vars('T7397');
echo count($t), "\n", $t['y'], "\n", $t['s'], "\n";
$i = get_class_vars(I7397::class);
echo is_array($i) ? count($i) : 'bad', "\n";
$e = get_class_vars(E7397::class);
echo array_key_exists('name', $e) && array_key_exists('value', $e) ? 'enum-ok' : 'enum-bad';
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'get_class_vars_interface_trait.php');
        ob_start();
        $rt->run($block);
        $this->assertSame("2\n2\nhi\n0\nenum-ok", ob_get_clean());
    }

    public function testVmGetClassVarsTraitStaticOnClass(): void
    {
        $code = <<<'PHP'
<?php
trait T7420 { public static int $a = 1; public static string $b = 'x'; }
class C7420 { use T7420; public static int $c = 2; }
class P7420 { public static int $p = 3; }
class D7420 extends P7420 {}
$v = get_class_vars(C7420::class);
echo count($v), "\n", $v['a'], "\n", $v['b'], "\n", $v['c'], "\n";
echo isset($v['hidden']) ? 'has-hidden' : 'no-hidden', "\n";
$p = get_class_vars(D7420::class);
echo count($p), "\n", $p['p'], "\n";
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'get_class_vars_trait_static.php');
        ob_start();
        $rt->run($block);
        $this->assertSame("3\n1\nx\n2\nno-hidden\n1\n3\n", ob_get_clean());
    }

    /** Issue #6603: get_class_vars() lists public hooked properties with declared defaults (null). */
    public function testVmGetClassVarsPropertyHooks(): void
    {
        $this->skipUnlessPropertyHooksEnabled();
        $code = <<<'PHP'
<?php
class C6603 {
    private string $backing = 'x';
    public string $title { get => 'hook:' . $this->backing; }
}
echo array_key_exists('title', get_class_vars(C6603::class)) ? "yes\n" : "no\n";
$vars = get_class_vars(C6603::class);
echo array_key_exists('title', $vars) && $vars['title'] === null ? "null-default\n" : "bad-default\n";
class G6603 {
    private string $x = 'g_only';
    public string $y { get => $this->x; }
}
$gVars = get_class_vars(G6603::class);
echo array_key_exists('y', $gVars) ? "g-yes\n" : "g-no\n";
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'get_class_vars_property_hooks.php');
        ob_start();
        $rt->run($block);
        $this->assertSame("yes\nnull-default\ng-yes\n", ob_get_clean());
    }
}
