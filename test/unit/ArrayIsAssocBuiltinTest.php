<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\array_is_assoc;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** VM builtin for array_is_assoc() (#7016). */
final class ArrayIsAssocBuiltinTest extends TestCase
{
    public function testEmptyListAssocAndHole(): void
    {
        $runtime = new Runtime();
        $fn = new array_is_assoc();

        $empty = new HashTable();
        $emptyFrame = $fn->getFrame($runtime->vmContext);
        $emptyArg = new VMVariable();
        $emptyArg->array($empty);
        $emptyFrame->calledArgs = [$emptyArg];
        $emptyFrame->returnVar = new VMVariable();
        $fn->execute($emptyFrame);
        $this->assertFalse($emptyFrame->returnVar->resolveIndirect()->toBool());

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
        $this->assertFalse($listFrame->returnVar->resolveIndirect()->toBool());

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
        $this->assertTrue($assocFrame->returnVar->resolveIndirect()->toBool());

        $hole = new HashTable();
        $zero = new VMVariable();
        $zero->int(1);
        $hole->addIndex(0, $zero);
        $two = new VMVariable();
        $two->int(2);
        $hole->addIndex(2, $two);
        $holeFrame = $fn->getFrame($runtime->vmContext);
        $holeArg = new VMVariable();
        $holeArg->array($hole);
        $holeFrame->calledArgs = [$holeArg];
        $holeFrame->returnVar = new VMVariable();
        $fn->execute($holeFrame);
        $this->assertTrue($holeFrame->returnVar->resolveIndirect()->toBool());
    }
}
