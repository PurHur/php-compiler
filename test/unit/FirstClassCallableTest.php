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

    /** Issue #9604: (new C)->f(...) without extra parens on new. */
    public function testVmInstanceMethodFirstClassCallableOnNewWithoutParens(): void
    {
        $code = <<<'PHP'
<?php
class C {
    public function f(): void { echo "ok\n"; }
}
$fn = (new C)->f(...);
$fn();
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'test.php');
        ob_start();
        $rt->run($block);
        $this->assertSame("ok\n", ob_get_clean());
    }

    /** Issue #10130: new Class(...) first-class callable must compile-fatal (Zend/zend_compile.c). */
    public function testVmNewExpressionFirstClassCallableCompileErrors(): void
    {
        $rt = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot create Closure for new expression');
        $rt->parseAndCompile(<<<'PHP'
<?php
declare(strict_types=1);
class Box {
    public function __construct(public int $v) {}
}
$maker = new Box(...);
echo $maker(42)->v, "\n";
PHP, 'test.php');
    }

    /** Issue #9604: trait-used instance method first-class callable. */
    public function testVmTraitMethodFirstClassCallable(): void
    {
        $code = <<<'PHP'
<?php
trait T { public function f(): void { echo "ok\n"; } }
class C { use T; }
$fn = (new C)->f(...);
$fn();
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'test.php');
        ob_start();
        $rt->run($block);
        $this->assertSame("ok\n", ob_get_clean());
    }

    /** Issue #9605: invokable object first-class callable (new C)(...). */
    public function testVmInvokableObjectFirstClassCallable(): void
    {
        $code = <<<'PHP'
<?php
class C {
    public function __invoke(): void {
        echo "ok\n";
    }
}
$fn = (new C)(...);
$fn();
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'test.php');
        ob_start();
        $rt->run($block);
        $this->assertSame("ok\n", ob_get_clean());
    }

    public function testVmInvokableObjectFirstClassCallableIsClosureObject(): void
    {
        $code = <<<'PHP'
<?php
class C {
    public function __invoke(): void {}
}
$fn = (new C)(...);
var_export($fn instanceof Closure);
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'test.php');
        ob_start();
        $rt->run($block);
        $this->assertSame('true', ob_get_clean());
    }

    /** Issue #9697: FCC in parameter defaults is not a constant expression (Zend/zend_compile.c). */
    public function testVmFunctionFirstClassCallableDefaultParameterCompileError(): void
    {
        $code = <<<'PHP'
<?php
class C {
    public function f(Closure $c = strlen(...)): int {
        return $c('abc');
    }
}
PHP;
        $rt = new Runtime();
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Constant expression contains invalid operations');
        $rt->parseAndCompile($code, 'test.php');
    }

    public function testVmStaticMethodFirstClassCallableDefaultParameterCompileError(): void
    {
        $code = <<<'PHP'
<?php
class S {
    public static function id(string $s): string { return $s; }
    public function g(Closure $c = S::id(...)): string { return $c('ok'); }
}
PHP;
        $rt = new Runtime();
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Constant expression contains invalid operations');
        $rt->parseAndCompile($code, 'test.php');
    }

    /** Issue #10472: inline parenthesized builtin FCC invoke `(strlen(...))($arg)`. */
    public function testVmInlineBuiltinFirstClassCallableInvoke(): void
    {
        $code = <<<'PHP'
<?php
echo (strlen(...))('abc');
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'test.php');
        ob_start();
        $rt->run($block);
        $this->assertSame('3', ob_get_clean());
    }

    /** Issue #10472: inline parenthesized user-function FCC invoke remains green (#4437). */
    public function testVmInlineUserFunctionFirstClassCallableInvoke(): void
    {
        $code = <<<'PHP'
<?php
function add(int $a, int $b): int { return $a + $b; }
echo (add(...))(2, 3);
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'test.php');
        ob_start();
        $rt->run($block);
        $this->assertSame('5', ob_get_clean());
    }

    /** Issue #10472: inline parenthesized enum static FCC `(E::from(...))($value)`. */
    public function testVmInlineBackedEnumFromFirstClassCallableInvoke(): void
    {
        $code = <<<'PHP'
<?php
enum E: string { case A = 'a'; }
echo (E::from(...))('a')->name;
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'test.php');
        ob_start();
        $rt->run($block);
        $this->assertSame('A', ob_get_clean());
    }

    public function testVmInstanceMethodFirstClassCallableDefaultParameterCompileError(): void
    {
        $code = <<<'PHP'
<?php
class C {
    public function id(): string { return 'ok'; }
    public function g(Closure $c = $this->id(...)): string { return $c(); }
}
PHP;
        $rt = new Runtime();
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Constant expression contains invalid operations');
        $rt->parseAndCompile($code, 'test.php');
    }
}
