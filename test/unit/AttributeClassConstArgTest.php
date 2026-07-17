<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #19908 */
final class AttributeClassConstArgTest extends TestCase
{
    public function testReflectionFunctionAttributeClassConstArg(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class T { public const X = 1; }
#[Attribute]
class A { public function __construct(public int $v) {} }
#[A(T::X)]
function f() {}
$attrs = (new ReflectionFunction('f'))->getAttributes();
var_export($attrs[0]->getArguments());
echo "\n", $attrs[0]->newInstance()->v, "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'attribute_class_const_arg.php'));
        $this->assertSame("array (\n  0 => 1,\n)\n1\n", ob_get_clean());
    }
}
