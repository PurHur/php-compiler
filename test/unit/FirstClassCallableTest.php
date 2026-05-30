<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** First-class callable syntax (issue #1230). */
final class FirstClassCallableTest extends TestCase
{
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
}
