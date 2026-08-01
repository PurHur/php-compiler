<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #26627 */
final class AttributeClassNameConstArgTest extends TestCase
{
    public function testSelfParentNamedClassFoldInAttributeCtor(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
#[Attribute(Attribute::TARGET_ALL | Attribute::IS_REPEATABLE)]
class Attr { public function __construct(public string $x) {} }
class B {}
class A extends B {
  #[Attr(self::class)]
  #[Attr(parent::class)]
  #[Attr(A::class)]
  function f() {}
}
$attrs = (new ReflectionMethod('A', 'f'))->getAttributes();
echo $attrs[0]->newInstance()->x, "\n";
echo $attrs[1]->newInstance()->x, "\n";
echo $attrs[2]->newInstance()->x, "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'attribute_class_name_const.php'));
        $this->assertSame("A\nB\nA\n", ob_get_clean());
    }

    public function testNamespacedSelfParentNamedClassFold(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
namespace N;
#[\Attribute(\Attribute::TARGET_ALL | \Attribute::IS_REPEATABLE)]
class Attr { public function __construct(public string $x) {} }
class B {}
class A extends B {
  #[Attr(self::class)]
  #[Attr(parent::class)]
  #[Attr(A::class)]
  function f() {}
}
$attrs = (new \ReflectionMethod(A::class, 'f'))->getAttributes();
echo $attrs[0]->newInstance()->x, "\n";
echo $attrs[1]->newInstance()->x, "\n";
echo $attrs[2]->newInstance()->x, "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'attribute_class_name_const_ns.php'));
        $this->assertSame("N\\A\nN\\B\nN\\A\n", ob_get_clean());
    }

    public function testStaticClassInAttributeCtorFatals(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
#[Attribute]
class Attr { public function __construct(public string $x) {} }
class A {
  #[Attr(static::class)]
  function f() {}
}
PHP;
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('static::class cannot be used for compile-time class name resolution');
        $runtime->parseAndCompile($code, 'attribute_static_class_const.php');
    }
}
