<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** Constructor property promotion (issue #1359). */
final class CtorPromotionTest extends TestCase
{
    public function testPromotedPropertyDefaultAndArgument(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class C {
    public function __construct(private string $x = 'a') {}
    public function get(): string {
        return $this->x;
    }
}
echo (new C())->get(), "\n";
echo (new C('b'))->get(), "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'ctor_promotion.php'));
        $out = ob_get_clean();
        $this->assertSame("a\nb\n", $out);
    }

    /** Untyped / mixed promotion matches Zend on VM (#32349). */
    public function testUntypedAndMixedPromotedProperty(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class A {
    public function __construct(public $x = 1) {}
}
echo (new A())->x, "\n";
echo (new A(7))->x, "\n";
class B {
    public function __construct(public mixed $y = 2) {}
}
echo (new B())->y, "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'ctor_promotion_untyped.php'));
        $out = ob_get_clean();
        $this->assertSame("1\n7\n2\n", $out);
    }

    public function testProtectedPromotedProperty(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class C {
    public function __construct(protected int $n) {}
    public function get(): int {
        return $this->n;
    }
}
echo (new C(7))->get();
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'ctor_promotion_protected.php'));
        $out = ob_get_clean();
        $this->assertSame('7', $out);
    }

    public function testIssue4395ReproReflectionAndVisibility(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class C {
    public function __construct(public int $x, private string $y = 'd') {}
}
$o = new C(3);
echo $o->x, "\n";
$r = new ReflectionClass(C::class);
$p = $r->getProperty('x');
echo ($p->isPublic() ? 'pub' : 'no'), "\n";
try {
    echo $o->y;
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'repro_promotion.php'));
        $out = ob_get_clean();
        $this->assertSame("3\npub\nError\n", $out);
    }

    public function testPromotedPropertyCollidesWithDeclaredProperty(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class C {
    public int $x = 0;
    public function __construct(public int $x) {}
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot redeclare C::$x');
        $runtime->parseAndCompile($code, 'promotion_collision.php');
    }

    /** Duplicate promoted ctor params match Zend wording (#29979). */
    public function testDuplicatePromotedCtorParamsAreRedefinitionOfParameter(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class A {
    public function __construct(public readonly int $a, public int $a) {}
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Redefinition of parameter $a');
        $runtime->parseAndCompile($code, 'promotion_dup_params.php');
    }
}
