<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Compiler\AttributeConstantEvaluator;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #21725 */
final class AttributeArithmeticConstArgTest extends TestCase
{
    public function testAttributeCtorPlusInstantiates(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
#[Attribute]
class A { public function __construct(public int $x) {} }
#[A(1 + 2)]
class C {}
$r = (new ReflectionClass(C::class))->getAttributes()[0]->newInstance();
echo $r->x, "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'attribute_arithmetic_plus.php'));
        $this->assertSame("3\n", ob_get_clean());
    }

    public function testAttributeCtorMulAndMod(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class A { public function __construct(public int $x) {} }
#[A(2 * 3)]
#[A(10 % 3)]
class C {}
$attrs = (new ReflectionClass(C::class))->getAttributes();
echo $attrs[0]->newInstance()->x, "\n";
echo $attrs[1]->newInstance()->x, "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'attribute_arithmetic_mul_mod.php'));
        $this->assertSame("6\n1\n", ob_get_clean());
    }

    public function testConstantEvaluatorPlusMinusDivPowUnary(): void
    {
        $parser = (new \PhpParser\ParserFactory())->createForHostVersion();
        foreach ([
            '1 + 2' => 3,
            '10 - 3' => 7,
            '10 / 4' => 2.5,
            '2 ** 3' => 8,
            '-(1 + 2)' => -3,
        ] as $src => $want) {
            $nodes = $parser->parse('<?php '.$src.';');
            $this->assertIsArray($nodes);
            $stmt = $nodes[0];
            $this->assertInstanceOf(\PhpParser\Node\Stmt\Expression::class, $stmt);
            $this->assertSame($want, AttributeConstantEvaluator::evalExpr($stmt->expr), $src);
        }
    }

    public function testNonConstVariableRejected(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$x = 1;
#[Attribute]
class A { public function __construct(public mixed $v) {} }
#[A($x)]
class C {}
PHP;
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Attribute constructor arguments must be compile-time constant expressions');
        $runtime->parseAndCompile($code, 'attribute_arithmetic_nonconst.php');
    }
}
