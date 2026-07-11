<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/**
 * Regression: compileTypeConstrainedVariable must not treat null cfgType as mixed
 * when the decl name string is provided (#12360, re-#12352).
 */
final class TypedPropertyTypeConstraintCompileTest extends TestCase
{
    public function testCompileTypeConstrainedVariableWithDeclNameStringSetsIntConstraint(): void
    {
        $runtime = new Runtime();
        $block = new Block(new \PHPCfg\Block());
        $type = \PHPTypes\Type::fromDecl('int');
        $method = new \ReflectionMethod(Compiler::class, 'compileTypeConstrainedVariable');
        $method->setAccessible(true);
        $slot = $method->invoke($runtime->compiler, $block, $type, 'int');
        self::assertArrayHasKey($slot, $block->constants);
        self::assertSame(Variable::TYPE_INTEGER, $block->constants[$slot]->typeConstraint);
    }

    public function testTypedPropertyClassBodyCarriesIntTypePrototype(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
class IntProp { public int $p; }
PHP, 'probe.php');
        self::assertNotNull($block);
        $classBlock = null;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_DECLARE_CLASS === $op->type) {
                $classBlock = $op->block1;
                break;
            }
        }
        self::assertNotNull($classBlock);
        $declareOp = null;
        foreach ($classBlock->opCodes as $op) {
            if (OpCode::TYPE_DECLARE_PROPERTY === $op->type) {
                $declareOp = $op;
                break;
            }
        }
        self::assertNotNull($declareOp);
        self::assertArrayHasKey($declareOp->arg3, $classBlock->constants);
        self::assertSame(
            Variable::TYPE_INTEGER,
            $classBlock->constants[$declareOp->arg3]->typeConstraint
        );
    }
}
