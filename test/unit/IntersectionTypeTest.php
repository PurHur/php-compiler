<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #1357 */
final class IntersectionTypeTest extends TestCase
{
    public function testIntersectionParamAcceptsImplementingObject(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface A {}
interface B {}
class C implements A, B {}
function f(A&B $x): int {
    return 1;
}
echo f(new C());
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'intersection_ok.php'));
        $this->assertSame('1', ob_get_clean());
    }

    public function testIntersectionParamRejectsNonObject(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface A {}
interface B {}
function f(A&B $x): void {}
f(1);
PHP;
        $this->expectException(\TypeError::class);
        $runtime->run($runtime->parseAndCompile($code, 'intersection_int.php'));
    }

    public function testIntersectionParamRejectsPartialImplementation(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface A {}
interface B {}
class OnlyA implements A {}
function f(A&B $x): void {}
f(new OnlyA());
PHP;
        $this->expectException(\TypeError::class);
        $runtime->run($runtime->parseAndCompile($code, 'intersection_partial.php'));
    }

    public function testIntersectionTypeMapsInPhpTypes(): void
    {
        $parser = new \PHPCfg\Parser((new \PhpParser\ParserFactory())->create(\PhpParser\ParserFactory::PREFER_PHP7));
        $script = $parser->parse('<?php interface A {} interface B {} function f(A&B $x) {}', 't.php');
        $param = $script->functions[0]->params[0];
        $type = \PHPTypes\Type::fromTypeDecl($param->declaredType);
        $this->assertSame(\PHPTypes\Type::TYPE_INTERSECTION, $type->type);
        $this->assertCount(2, $type->subTypes);
    }

    public function testIntersectionParamParsesAndCompiles(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface X {}
interface Y {}
function g(X&Y $v): void {}
PHP;
        $runtime->parseAndCompile($code, 'intersection_parse.php');
        $this->addToAssertionCount(1);
    }
}
