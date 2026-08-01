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

    /** @covers issue #6499 */
    public function testIntersectionReturnAcceptsImplementingObject(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface A {}
interface B {}
class C implements A, B {}
function f(): A&B { return new C(); }
class D { public function m(): A&B { return new C(); } }
echo get_class(f());
echo get_class((new D())->m());
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'intersection_return_ok.php'));
        $this->assertSame('CC', ob_get_clean());
    }

    /** @covers issue #6499 */
    public function testIntersectionReturnRejectsIncompatibleValue(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface A {}
interface B {}
function f(): A&B { return new stdClass(); }
f();
PHP;
        $this->expectException(\TypeError::class);
        $runtime->run($runtime->parseAndCompile($code, 'intersection_return_bad.php'));
    }

    /** @covers issue #6499 */
    public function testIntersectionReturnTypeMapsInPhpTypes(): void
    {
        $parser = new \PHPCfg\Parser((new \PhpParser\ParserFactory())->create(\PhpParser\ParserFactory::PREFER_PHP7));
        $script = $parser->parse('<?php interface A {} interface B {} function f(): A&B {}', 't.php');
        $type = \PHPTypes\Type::fromTypeDecl($script->functions[0]->returnType);
        $this->assertSame(\PHPTypes\Type::TYPE_INTERSECTION, $type->type);
        $this->assertCount(2, $type->subTypes);
    }

    /** @covers issue #14542 */
    public function testIntersectionClassAndInterfaceMembersAcceptConcreteSubclass(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface IntersectionIface {}
class IntersectionBase {}
class IntersectionConcrete extends IntersectionBase implements IntersectionIface {}

function accepts_intersection(IntersectionBase&IntersectionIface $value): void {}

function returns_intersection(): IntersectionBase&IntersectionIface {
    return new IntersectionConcrete();
}

class Holder {
    public IntersectionBase&IntersectionIface $prop;

    public function __construct() {
        $this->prop = new IntersectionConcrete();
    }
}

$c = new IntersectionConcrete();
accepts_intersection($c);
echo get_class(returns_intersection()), "\n";
$h = new Holder();
echo get_class($h->prop), "\n";
echo "ok\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'intersection_class_interface.php'));
        $this->assertSame("IntersectionConcrete\nIntersectionConcrete\nok\n", ob_get_clean());
    }

    /** @covers issue #26605 */
    public function testDuplicateIntersectionParamIsCompileFatal(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface A {}
interface B {}
function f(A&B&A $x) {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Duplicate type A is redundant');
        $runtime->parseAndCompile($code, 'intersection_dup_param.php');
    }

    /** @covers issue #26605 */
    public function testDuplicateIntersectionReturnIsCompileFatal(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface A {}
interface B {}
function f(): A&B&A { throw new Exception(); }
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Duplicate type A is redundant');
        $runtime->parseAndCompile($code, 'intersection_dup_return.php');
    }

    /** @covers issue #26605 */
    public function testDuplicateIntersectionPropertyIsCompileFatal(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface A {}
interface B {}
class C { public A&B&A $x; }
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Duplicate type A is redundant');
        $runtime->parseAndCompile($code, 'intersection_dup_prop.php');
    }

    /** @covers issue #26605 */
    public function testDuplicateTraversableIntersectionIsCompileFatal(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function f(Traversable&Countable&Traversable $x) {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Duplicate type Traversable is redundant');
        $runtime->parseAndCompile($code, 'intersection_dup_traversable.php');
    }

    /** @covers issue #26605 */
    public function testDuplicateIntersectionCaseInsensitiveUsesSecondSpelling(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function f(Traversable&traversable $x) {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Duplicate type traversable is redundant');
        $runtime->parseAndCompile($code, 'intersection_dup_case.php');
    }
}
