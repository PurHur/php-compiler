<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Compiler\AttributeConstantEvaluator;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #26030 */
final class AttributeBuiltinMagicConstArgTest extends TestCase
{
    public function testAttributeCtorPhpIntMax(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
#[Attribute]
class A { public function __construct(public mixed $v) {} }
#[A(PHP_INT_MAX)]
class C {}
echo (new ReflectionClass(C::class))->getAttributes()[0]->newInstance()->v, "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'attribute_builtin_php_int_max.php'));
        $this->assertSame((string) \PHP_INT_MAX."\n", ob_get_clean());
    }

    public function testAttributeCtorEErrorAndSortString(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class A { public function __construct(public mixed $v) {} }
#[A(E_ERROR)]
#[A(SORT_STRING)]
class C {}
$attrs = (new ReflectionClass(C::class))->getAttributes();
echo $attrs[0]->newInstance()->v, "\n";
echo $attrs[1]->newInstance()->v, "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'attribute_builtin_e_error_sort.php'));
        $this->assertSame("1\n2\n", ob_get_clean());
    }

    public function testAttributeCtorDirFileLine(): void
    {
        $runtime = new Runtime();
        $file = sys_get_temp_dir().'/attribute_magic_dir_file_line_'.getmypid().'.php';
        $code = <<<'PHP'
<?php
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class A { public function __construct(public mixed $v) {} }
#[A(__DIR__)]
#[A(__FILE__)]
#[A(__LINE__)]
class C {}
$attrs = (new ReflectionClass(C::class))->getAttributes();
echo $attrs[0]->newInstance()->v, "\n";
echo $attrs[1]->newInstance()->v, "\n";
echo $attrs[2]->newInstance()->v, "\n";
PHP;
        file_put_contents($file, $code);
        try {
            ob_start();
            $runtime->run($runtime->parseAndCompile($code, $file));
            $out = ob_get_clean();
            $real = realpath($file);
            $this->assertNotFalse($real);
            $want = dirname($real)."\n".$real."\n6\n";
            $this->assertSame($want, $out);
        } finally {
            @unlink($file);
        }
    }

    public function testConstantEvaluatorResolvesPhpIntMax(): void
    {
        $parser = (new \PhpParser\ParserFactory())->createForHostVersion();
        $nodes = $parser->parse('<?php PHP_INT_MAX;');
        $this->assertIsArray($nodes);
        $stmt = $nodes[0];
        $this->assertInstanceOf(\PhpParser\Node\Stmt\Expression::class, $stmt);
        $this->assertSame(\PHP_INT_MAX, AttributeConstantEvaluator::evalExpr($stmt->expr));
    }

    public function testArithmeticAndClassStillOk(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class A { public function __construct(public mixed $v) {} }
#[A(1 + 2)]
#[A(__CLASS__)]
#[A(Attribute::TARGET_CLASS)]
class C {}
$attrs = (new ReflectionClass(C::class))->getAttributes();
echo $attrs[0]->newInstance()->v, "\n";
echo $attrs[1]->newInstance()->v, "\n";
echo $attrs[2]->newInstance()->v, "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'attribute_control_arith_class.php'));
        $this->assertSame("3\nC\n1\n", ob_get_clean());
    }
}
