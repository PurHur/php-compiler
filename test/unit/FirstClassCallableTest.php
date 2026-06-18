<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** First-class callable syntax (issue #1230). */
final class FirstClassCallableTest extends TestCase
{
    public function testVmFunctionFirstClassCallableIsClosureObject(): void
    {
        $code = <<<'PHP'
<?php
$fn = strlen(...);
var_export(is_object($fn));
var_export($fn instanceof Closure);
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'test.php');
        ob_start();
        $rt->run($block);
        $this->assertSame('truetrue', ob_get_clean());
    }

    public function testVmFunctionFirstClassCallable(): void
    {
        $code = <<<'PHP'
<?php
$fn = strlen(...);
echo $fn('abc');
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'test.php');
        ob_start();
        $rt->run($block);
        $this->assertSame('3', ob_get_clean());
    }

    public function testVmStaticMethodFirstClassCallable(): void
    {
        $code = <<<'PHP'
<?php
class C {
    public static function id() { return 'ok'; }
}
$fn = C::id(...);
echo $fn();
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'test.php');
        ob_start();
        $rt->run($block);
        $this->assertSame('ok', ob_get_clean());
    }

    public function testVmInstanceMethodFirstClassCallable(): void
    {
        $code = <<<'PHP'
<?php
class C {
    public function len(): int { return 3; }
}
$c = new C();
$f = $c->len(...);
echo $f();
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'test.php');
        ob_start();
        $rt->run($block);
        $this->assertSame('3', ob_get_clean());
    }

    public function testVmInstanceMethodFirstClassCallableForwardsArguments(): void
    {
        $code = <<<'PHP'
<?php
class C {
    public function add(int $a, int $b): int { return $a + $b; }
}
$c = new C();
$f = $c->add(...);
echo $f(2, 3);
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'test.php');
        ob_start();
        $rt->run($block);
        $this->assertSame('5', ob_get_clean());
    }

    /** Issue #6851: enum case value as first-class callable must compile then Error at runtime. */
    public function testVmEnumCaseValueFirstClassCallableThrowsError(): void
    {
        $code = <<<'PHP'
<?php
enum E: int {
    case A = 1;
}
try {
    (E::A)(...);
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'test.php');
        ob_start();
        $rt->run($block);
        $this->assertSame("Error: Object of type E is not callable\n", ob_get_clean());
    }

    /** Issue #6845: enum case instance method first-class callable (E::A->f(...)). */
    public function testVmEnumCaseMethodFirstClassCallable(): void
    {
        $code = <<<'PHP'
<?php
enum E {
    case A;
    public function f(): string { return 'a'; }
}
$c = E::A->f(...);
echo $c();
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'test.php');
        ob_start();
        $rt->run($block);
        $this->assertSame('a', ob_get_clean());
    }

    /** Issue #7025: backed enum E::from(...)/tryFrom(...) first-class static callable. */
    public function testVmBackedEnumFromFirstClassCallable(): void
    {
        $code = <<<'PHP'
<?php
enum E: int {
    case A = 1;
}
$from = E::from(...);
echo $from(1)->name;
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'test.php');
        ob_start();
        $rt->run($block);
        $this->assertSame('A', ob_get_clean());
    }

    public function testVmBackedEnumTryFromFirstClassCallableReturnsNull(): void
    {
        $code = <<<'PHP'
<?php
enum E: int {
    case A = 1;
}
$tryFrom = E::tryFrom(...);
var_export($tryFrom(99));
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'test.php');
        ob_start();
        $rt->run($block);
        $this->assertSame('NULL', ob_get_clean());
    }

    /** Issue #4957: TypeReconstructor must not call missing Type::array(). */
    public function testVmInstanceMethodFirstClassCallableOnNewExpression(): void
    {
        $code = <<<'PHP'
<?php
class Box {
    public function add(int $a, int $b): int { return $a + $b; }
}
$f = (new Box())->add(...);
echo $f(1, 2);
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'test.php');
        ob_start();
        $rt->run($block);
        $this->assertSame('3', ob_get_clean());
    }

    /** Issue #9170: FCC in parameter defaults (PHP 8.5 constant expressions). */
    public function testVmFunctionFirstClassCallableDefaultParameter(): void
    {
        $code = <<<'PHP'
<?php
class C {
    public function f(Closure $c = strlen(...)): int {
        return $c('abc');
    }
}
echo (new C)->f();
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'test.php');
        ob_start();
        $rt->run($block);
        $this->assertSame('3', ob_get_clean());
    }

    public function testVmStaticMethodFirstClassCallableDefaultParameter(): void
    {
        $code = <<<'PHP'
<?php
class S {
    public static function id(string $s): string { return $s; }
    public function g(Closure $c = S::id(...)): string { return $c('ok'); }
}
echo (new S)->g();
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'test.php');
        ob_start();
        $rt->run($block);
        $this->assertSame('ok', ob_get_clean());
    }
}
