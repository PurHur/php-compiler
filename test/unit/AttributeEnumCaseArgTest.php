<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Compiler\AttributeConstantEvaluator;
use PHPCompiler\Compiler\CompileTimeEnumCase;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #9988 */
final class AttributeEnumCaseArgTest extends TestCase
{
    public function testEvalArgAcceptsEnumCaseFetch(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
enum E: int { case A = 1; }
#[SomeAttr(E::A)]
class C {}
class SomeAttr { public function __construct(public mixed $v) {} }
$args = (new ReflectionClass(C::class))->getAttributes()[0]->getArguments();
echo $args[0] instanceof E ? 'enum' : 'other';
echo "\n";
echo $args[0]->name;
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'attribute_enum_case_arg.php'));
        $this->assertSame("enum\nA", ob_get_clean());
    }

    public function testConstantEvaluatorReturnsCompileTimeEnumCase(): void
    {
        $parser = (new \PhpParser\ParserFactory())->createForHostVersion();
        $nodes = $parser->parse('<?php E::A;');
        $this->assertIsArray($nodes);
        $stmt = $nodes[0];
        $this->assertInstanceOf(\PhpParser\Node\Stmt\Expression::class, $stmt);
        $expr = $stmt->expr;
        $this->assertInstanceOf(\PhpParser\Node\Expr\ClassConstFetch::class, $expr);
        $value = AttributeConstantEvaluator::evalExpr($expr);
        $this->assertInstanceOf(CompileTimeEnumCase::class, $value);
        $this->assertSame('E', $value->enumName);
        $this->assertSame('A', $value->caseName);
    }
}
