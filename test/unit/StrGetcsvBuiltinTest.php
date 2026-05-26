<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\str_getcsv;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** VM builtin for str_getcsv(). */
final class StrGetcsvBuiltinTest extends TestCase
{
    public function testSimpleRow(): void
    {
        $runtime = new Runtime();
        $fn = new str_getcsv();
        $frame = $fn->getFrame($runtime->vmContext);
        $arg = new VMVariable();
        $arg->string('a,b,c');
        $frame->calledArgs = [$arg];
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);
        $ht = $frame->returnVar->toArray();
        $this->assertSame(3, $ht->getNumElements());
        $vals = [];
        foreach ($ht->iterate(true) as $v) {
            $vals[] = $v->resolveIndirect()->toString();
        }
        $this->assertSame(['a', 'b', 'c'], $vals);
    }

    public function testQuotedFields(): void
    {
        $runtime = new Runtime();
        $fn = new str_getcsv();
        $frame = $fn->getFrame($runtime->vmContext);
        $arg = new VMVariable();
        $arg->string('"hello","world"');
        $frame->calledArgs = [$arg];
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);
        $ht = $frame->returnVar->toArray();
        $vals = [];
        foreach ($ht->iterate(true) as $v) {
            $vals[] = $v->resolveIndirect()->toString();
        }
        $this->assertSame(['hello', 'world'], $vals);
    }
}
