<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Array union (`+`) JIT bridge after #18409 deleted ArrayBuiltinHelper::arrayUnion (#10533). */
final class HashTableUnionRuntimeTest extends TestCase
{
    public function testArrayBuiltinHelperExposesArrayUnion(): void
    {
        $this->assertTrue(method_exists(\PHPCompiler\JIT\ArrayBuiltinHelper::class, 'arrayUnion'));
        $this->assertTrue(method_exists(\PHPCompiler\JIT\Builtin\HashTableUnionRuntime::class, 'union'));
        $this->assertTrue(method_exists(\PHPCompiler\VM\HashTableJitHelper::class, 'unionCopy'));
    }

    public function testHelperWiresValueHashtablePlus(): void
    {
        $helper = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT/Helper.php');
        $this->assertStringContainsString('ArrayBuiltinHelper::arrayUnion', $helper);
        $this->assertStringContainsString('Variable::TYPE_VALUE === $rightType', $helper);
        $this->assertStringContainsString('Variable::TYPE_VALUE === $leftType && $rightIsArray', $helper);
    }

    public function testVmUnionCopyLeftKeysWin(): void
    {
        $left = new \PHPCompiler\VM\HashTable();
        $a = new \PHPCompiler\VM\Variable();
        $a->int(1);
        $left->addIndex(0, $a);
        $b = new \PHPCompiler\VM\Variable();
        $b->string('keep');
        $left->add('k', $b);

        $right = new \PHPCompiler\VM\HashTable();
        $c = new \PHPCompiler\VM\Variable();
        $c->int(99);
        $right->addIndex(0, $c);
        $d = new \PHPCompiler\VM\Variable();
        $d->string('new');
        $right->add('k2', $d);
        $e = new \PHPCompiler\VM\Variable();
        $e->string('overwrite');
        $right->add('k', $e);

        $out = \PHPCompiler\VM\HashTableJitHelper::unionCopy($left, $right);
        $this->assertSame(1, $out->findIndex(0)?->toInt());
        $this->assertSame('keep', $out->find('k')?->toString());
        $this->assertSame('new', $out->find('k2')?->toString());
    }
}
