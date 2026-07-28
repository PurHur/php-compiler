<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Compiler + VM smoke for anonymous closures (#72, #142). */
final class ClosureCompileTest extends TestCase
{
    public function testPhpcRunClosureInline(): void
    {
        $repo = dirname(__DIR__, 2);
        $cmd = 'cd '.escapeshellarg($repo)
            .' && ./phpc run -r '.escapeshellarg('$f = function($x) { return $x + 1; }; echo $f(2);')
            .' 2>&1';
        exec($cmd, $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
        $this->assertStringContainsString('3', implode("\n", $out));
    }

    public function testPhpcRunArrowInline(): void
    {
        $repo = dirname(__DIR__, 2);
        $cmd = 'cd '.escapeshellarg($repo)
            .' && ./phpc run -r '.escapeshellarg('$f = fn($x) => $x * 2; echo $f(3);')
            .' 2>&1';
        exec($cmd, $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
        $this->assertStringContainsString('6', implode("\n", $out));
    }

    /** Regression: Closure::bind(inline closure, ...) must send closure not hoisted C::class (#3673). */
    public function testVmClosureBindStaticInlineClosure(): void
    {
        $code = <<<'PHP'
<?php
class C { private function sec(): string { return 'ok'; } }
$c = new C;
$f = Closure::bind(function (): string { return $this->sec(); }, $c, C::class);
echo $f(), "\n";
PHP;
        $rt = new PHPCompiler\Runtime();
        $block = $rt->parseAndCompile($code, 'test.php');
        ob_start();
        $rt->run($block);
        $this->assertSame("ok\n", ob_get_clean());
    }

    /** Regression: inline closure bindTo($obj, null) must send null scope not receiver (#14893). */
    public function testVmInlineClosureBindToNullScope(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);
class C { public int $p = 9; }
$c = new C();
$readPublic = (function (): int { return $this->p; })->bindTo($c, null);
echo $readPublic(), "\n";
PHP;
        $rt = new PHPCompiler\Runtime();
        $block = $rt->parseAndCompile($code, 'test.php');
        ob_start();
        $rt->run($block);
        $this->assertSame("9\n", ob_get_clean());
    }

    /** Regression: Closure::bind($closure, new C(), null) must bind $this like bindTo (#18880). */
    public function testVmStaticClosureBindNullScope(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);
class C { public int $x = 5; }
$c = function (): int { return $this->x; };
echo Closure::bind($c, new C(), null)(), "\n";
PHP;
        $rt = new PHPCompiler\Runtime();
        $block = $rt->parseAndCompile($code, 'test.php');
        ob_start();
        $rt->run($block);
        $this->assertSame("5\n", ob_get_clean());
    }

    /** Regression: bindTo(null, ClassName::class) on static closure — scope not misbound as $newThis (#15899). */
    public function testVmStaticClosureBindToNullClassScope(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);
class C { public static int $x = 1; }
$fn = static function (): int { return self::$x; };
echo ($fn->bindTo(null, C::class))(), "\n";
PHP;
        $rt = new PHPCompiler\Runtime();
        $block = $rt->parseAndCompile($code, 'test.php');
        ob_start();
        $rt->run($block);
        $this->assertSame("1\n", ob_get_clean());
    }

    /** Regression: bindTo(new C(), null) must bind inline new object not receiver (#15900). */
    public function testVmInlineClosureBindToInlineNewObject(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);
class C { public int $x = 1; }
echo (function (): int { return $this->x; })->bindTo(new C(), null)(), "\n";
$o = new C();
echo (function (): int { return $this->x; })->bindTo($o, null)(), "\n";
PHP;
        $rt = new PHPCompiler\Runtime();
        $block = $rt->parseAndCompile($code, 'test.php');
        ob_start();
        $rt->run($block);
        $this->assertSame("1\n1\n", ob_get_clean());
    }

    /** Regression: Closure::bind(inline closure, enum case, Enum::class) — arg #0 closure not scope (#16722). */
    public function testVmClosureBindStaticEnumCase(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);
enum E: int { case A = 1; public function m(): int { return $this->value; } }
$c = Closure::bind(function (): int { return $this->value; }, E::A, E::class);
echo $c(), "\n";
PHP;
        $rt = new PHPCompiler\Runtime();
        $block = $rt->parseAndCompile($code, 'test.php');
        ob_start();
        $rt->run($block);
        $this->assertSame("1\n", ob_get_clean());
    }

    /** Regression: Closure::bind(inline closure, new C(), Scope::class) — arg #0 closure not newThis (#17633). */
    public function testVmClosureBindStaticInlineNewPrivateMethod(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);
class A { private function f(): string { return 'ok'; } }
$c = Closure::bind(function (): string { return $this->f(); }, new A(), A::class);
echo $c(), "\n";
PHP;
        $rt = new PHPCompiler\Runtime();
        $block = $rt->parseAndCompile($code, 'test.php');
        ob_start();
        $rt->run($block);
        $this->assertSame("ok\n", ob_get_clean());
    }

    /** Regression: global $f = closure() must keep closureState after materialize (#17723). */
    public function testMaterializeConstantValuePreservesClosureState(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);
$f = function (): int {
    return 42;
};
echo $f(), "\n";
PHP;
        $rt = new PHPCompiler\Runtime();
        $block = $rt->parseAndCompile($code, 'test.php');
        ob_start();
        $rt->run($block);
        $this->assertSame("42\n", ob_get_clean());
    }

    /**
     * Regression: Closure::bind scope must survive CFG edges for private static ?? / if (#24335).
     * Block::getFrame previously dropped calledClass on branch frames.
     */
    public function testVmBoundClosurePrivateStaticCoalesceAndIf(): void
    {
        $code = <<<'PHP'
<?php
class A { private static $v = 7; }
class WrongScope {}
$coalesce = Closure::bind(static function () { return A::$v ?? 'no'; }, null, A::class);
echo 'c=', $coalesce(), "\n";
$direct = Closure::bind(static function () { return A::$v; }, null, A::class);
echo 'd=', $direct(), "\n";
$branched = Closure::bind(static function () {
    if (1) { return A::$v; }
    return 0;
}, null, A::class);
echo 'i=', $branched(), "\n";
try {
    $wrong = Closure::bind(static function () { return A::$v; }, null, WrongScope::class);
    echo 'w=', $wrong(), "\n";
} catch (Throwable $t) {
    echo 'e=', $t->getMessage(), "\n";
}
PHP;
        $rt = new PHPCompiler\Runtime();
        $block = $rt->parseAndCompile($code, 'test.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertStringContainsString('c=7', $out);
        $this->assertStringContainsString('d=7', $out);
        $this->assertStringContainsString('i=7', $out);
        $this->assertStringContainsString('e=Cannot access private property A::$v', $out);
    }
}
