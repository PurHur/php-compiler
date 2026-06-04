<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #5418 */
final class AttributeConstructorNewTest extends TestCase
{
    public function testNewInAttributeConstructorMaterializesOnReflection(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
#[Attribute]
class SomeAttr {
    public function __construct(public object $o) {}
}
#[SomeAttr(new stdClass())]
class C {}
var_dump((new ReflectionClass(C::class))->getAttributes()[0]->newInstance()->o);
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'attribute_constructor_new.php'));
        $out = ob_get_clean();
        $this->assertMatchesRegularExpression('/object\(stdClass\)#\d+/', $out);
    }
}
