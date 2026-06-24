<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #6833 */
final class ScalarUnionCompileTest extends TestCase
{
    public function test_scalar_union_param_property_and_return_compile(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
declare(strict_types=1);
function f(int|string $x): int|string {
    return $x;
}
echo f(1), f('z');

class C {
    public int|string $p;
}
$c = new C();
$c->p = 'ok';
echo $c->p;
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'scalar_union.php'));
        $this->assertSame('1zok', ob_get_clean());
    }

    public function test_scalar_union_null_assignment_throws_type_error(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class T {
    public int|string $x;
}
$t = new T();
try {
    $t->x = null;
    echo "assigned='", $t->x, "'";
} catch (TypeError $e) {
    echo $e->getMessage();
}
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'scalar_union_null.php'));
        $this->assertSame(
            'Cannot assign null to property T::$x of type string|int',
            ob_get_clean()
        );
    }

    public function test_scalar_union_decl_order_canonicalizes_like_zend(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class A {
    public string|int $a;
}
class B {
    public int|string $b;
}
foreach ([new A(), new B()] as $obj) {
    $prop = $obj instanceof A ? 'a' : 'b';
    try {
        $obj->{$prop} = null;
    } catch (TypeError $e) {
        echo $e->getMessage(), "\n";
    }
}
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'scalar_union_order.php'));
        $this->assertSame(
            "Cannot assign null to property A::\$a of type string|int\n"
            . "Cannot assign null to property B::\$b of type string|int\n",
            ob_get_clean()
        );
    }

    public function test_scalar_union_nullable_property_accepts_null(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class T {
    public int|string|null $x;
}
$t = new T();
$t->x = null;
echo var_export($t->x, true);
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'scalar_union_nullable.php'));
        $this->assertSame('NULL', ob_get_clean());
    }

    public function test_scalar_union_uninitialized_property_read_throws_error(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class C {
    public int|string $p;
}
$c = new C();
try {
    echo $c->p;
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'scalar_union_uninit.php'));
        $this->assertSame(
            "Typed property C::\$p must not be accessed before initialization\n",
            ob_get_clean()
        );
    }
}
