<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\CompilerVersion;
use PHPCompiler\VM\ClassConstMaterializer;
use PHPUnit\Framework\TestCase;

/**
 * JIT/AOT class-constant materialization for bare `new UserClass` (#19046, Zend/zend_compile.c).
 */
final class ClassConstNewWithoutParensMaterializerTest extends TestCase
{
    public function testSeedReferencedClassesMaterializesBareNewUserClass(): void
    {
        if (!CompilerVersion::supportsNewWithoutParensInConstAndStaticInitializers()) {
            $this->markTestSkipped('bare new in class constants requires PHP_COMPILER_PROFILE=8.4');
        }
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Foo {
    public function __construct(public int $n = 7) {}
}
class Holder {
    public const BAR = new Foo;
}
PHP;
        $block = $runtime->parseAndCompile($code, 'const_new_without_parens.php');
        $this->assertNotNull($block);

        $holderBlock = null;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_DECLARE_CLASS !== $op->type) {
                continue;
            }
            $name = $this->literalOperandString($block->getOperand($op->arg1));
            if ('Holder' === $name) {
                $holderBlock = $op->block1;
                break;
            }
        }
        $this->assertNotNull($holderBlock);

        $barSlot = null;
        foreach ($holderBlock->opCodes as $op) {
            if (OpCode::TYPE_DECLARE_CLASS_CONST !== $op->type) {
                continue;
            }
            $constName = $this->literalOperandString($holderBlock->getOperand($op->arg1));
            if ('BAR' === $constName) {
                $barSlot = $op->arg2;
                break;
            }
        }
        $this->assertNotNull($barSlot);

        $vm = new VM($runtime->vmContext);
        ClassConstMaterializer::seedReferencedClasses($vm, $block, $holderBlock, $barSlot);
        $value = ClassConstMaterializer::materializeSlot($vm, $holderBlock, $barSlot, 'Holder');
        $this->assertTrue($value->is(VM\Variable::TYPE_OBJECT));
        $obj = $value->toObject();
        $this->assertSame('Foo', $obj->class->name);
        $props = $obj->propertiesWithNames();
        $this->assertArrayHasKey('n', $props);
        $this->assertSame(7, $props['n']->toInt());
    }

    private function literalOperandString(object $op): string
    {
        if (property_exists($op, 'value')) {
            return (string) $op->value;
        }

        return '';
    }
}
