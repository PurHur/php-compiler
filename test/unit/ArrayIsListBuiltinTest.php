<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\array_is_list;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** VM builtin for array_is_list() (#2211). */
final class ArrayIsListBuiltinTest extends TestCase
{
    public function testEmptyPackedAndAssoc(): void
    {
        $runtime = new Runtime();
        $fn = new array_is_list();

        $empty = new HashTable();
        $emptyFrame = $fn->getFrame($runtime->vmContext);
        $emptyArg = new VMVariable();
        $emptyArg->array($empty);
        $emptyFrame->calledArgs = [$emptyArg];
        $emptyFrame->returnVar = new VMVariable();
        $fn->execute($emptyFrame);
        $this->assertTrue($emptyFrame->returnVar->resolveIndirect()->toBool());

        $list = new HashTable();
        foreach ([10, 20, 30] as $i => $val) {
            $cell = new VMVariable();
            $cell->int($val);
            $list->addIndex($i, $cell);
        }
        $listFrame = $fn->getFrame($runtime->vmContext);
        $listArg = new VMVariable();
        $listArg->array($list);
        $listFrame->calledArgs = [$listArg];
        $listFrame->returnVar = new VMVariable();
        $fn->execute($listFrame);
        $this->assertTrue($listFrame->returnVar->resolveIndirect()->toBool());

        $assoc = new HashTable();
        $a = new VMVariable();
        $a->int(1);
        $assoc->add('x', $a);
        $assocFrame = $fn->getFrame($runtime->vmContext);
        $assocArg = new VMVariable();
        $assocArg->array($assoc);
        $assocFrame->calledArgs = [$assocArg];
        $assocFrame->returnVar = new VMVariable();
        $fn->execute($assocFrame);
        $this->assertFalse($assocFrame->returnVar->resolveIndirect()->toBool());
    }

    public function testHoleRejectsList(): void
    {
        $runtime = new Runtime();
        $fn = new array_is_list();
        $ht = new HashTable();
        $zero = new VMVariable();
        $zero->int(1);
        $ht->addIndex(0, $zero);
        $two = new VMVariable();
        $two->int(2);
        $ht->addIndex(2, $two);

        $frame = $fn->getFrame($runtime->vmContext);
        $arg = new VMVariable();
        $arg->array($ht);
        $frame->calledArgs = [$arg];
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);
        $this->assertFalse($frame->returnVar->resolveIndirect()->toBool());
    }

    /** Unset last key then restore — still a list (php-src packed shrink / #28051). */
    public function testUnsetRestoreTrailingKeyIsList(): void
    {
        $runtime = new Runtime();
        $fn = new array_is_list();
        $ht = new HashTable();
        foreach ([1, 2] as $i => $val) {
            $cell = new VMVariable();
            $cell->int($val);
            $ht->addIndex($i, $cell);
        }
        $idx = new VMVariable();
        $idx->int(1);
        $ht->offsetUnset($idx);
        $restored = new VMVariable();
        $restored->int(3);
        $ht->updateIndex(1, $restored);

        $frame = $fn->getFrame($runtime->vmContext);
        $arg = new VMVariable();
        $arg->array($ht);
        $frame->calledArgs = [$arg];
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);
        $this->assertSame(2, $ht->getNumElements());
        $this->assertTrue($frame->returnVar->resolveIndirect()->toBool());
    }
}
