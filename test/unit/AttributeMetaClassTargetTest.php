<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Compiler\AttributeNames;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #25723 */
final class AttributeMetaClassTargetTest extends TestCase
{
    public function testRejectsAttributeMetaOnFunction(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
#[Attribute]
function f() { return 1; }
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(
            'Attribute "Attribute" cannot target function (allowed targets: class)'
        );
        $runtime->parseAndCompile($code, 'attribute_meta_function.php');
    }

    public function testRejectsAttributeMetaOnMethod(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class C {
    #[Attribute]
    function m() {}
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(
            'Attribute "Attribute" cannot target method (allowed targets: class)'
        );
        $runtime->parseAndCompile($code, 'attribute_meta_method.php');
    }

    public function testAllowsAttributeMetaOnClass(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
#[Attribute]
class Marker {}
echo class_exists(Marker::class) ? "ok\n" : "no\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'attribute_meta_class.php'));
        $this->assertSame("ok\n", ob_get_clean());
    }

    public function testAssertHelperRejectsNonClassTargets(): void
    {
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(
            'Attribute "Attribute" cannot target parameter (allowed targets: class)'
        );
        AttributeNames::assertAttributeMetaClassTargetOnly(['Attribute'], 'parameter');
    }
}
