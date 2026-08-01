<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #26628 */
final class AttributeUserlandConstArgTest extends TestCase
{
    public function testGlobalConstFoldsInAttributeCtor(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
#[Attribute]
class Attr { public function __construct(public int $x) {} }
const G = 4;
#[Attr(G)]
function f() {}
echo (new ReflectionFunction("f"))->getAttributes()[0]->newInstance()->x, "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'attribute_userland_global_const.php'));
        $this->assertSame("4\n", ob_get_clean());
    }

    public function testNamespacedConstFoldsInAttributeCtor(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
namespace N;
#[\Attribute]
class Attr { public function __construct(public int $x) {} }
const C = 7;
#[Attr(C)]
function f() {}
echo (new \ReflectionFunction(__NAMESPACE__ . "\\f"))->getAttributes()[0]->newInstance()->x, "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'attribute_userland_ns_const.php'));
        $this->assertSame("7\n", ob_get_clean());
    }

    public function testUseConstAliasFoldsInAttributeCtor(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
namespace Other;
const X = 9;
namespace N;
use const Other\X as Alias;
#[\Attribute]
class Attr { public function __construct(public int $x) {} }
#[Attr(Alias)]
function f() {}
echo (new \ReflectionFunction(__NAMESPACE__ . "\\f"))->getAttributes()[0]->newInstance()->x, "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'attribute_userland_use_const.php'));
        $this->assertSame("9\n", ob_get_clean());
    }

    public function testUndefinedConstStillRejectedAtCompile(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
#[Attribute]
class Attr { public function __construct(public mixed $x) {} }
#[Attr(MISSING)]
function f() {}
PHP;
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Attribute constructor arguments must be compile-time constant expressions');
        $runtime->parseAndCompile($code, 'attribute_userland_undef_const.php');
    }

    public function testBuiltinConstStillFolds(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
#[Attribute]
class Attr { public function __construct(public int $x) {} }
#[Attr(PHP_INT_SIZE)]
function f() {}
echo (new ReflectionFunction("f"))->getAttributes()[0]->newInstance()->x, "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'attribute_userland_builtin_control.php'));
        $this->assertSame((string) \PHP_INT_SIZE."\n", ob_get_clean());
    }
}
