<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\proc_nice;
use PHPCompiler\ext\standard\VmProcNiceNative;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** VM builtin for proc_nice() (#5181, #12183). */
final class ProcNiceBuiltinTest extends TestCase
{
    public function testProcNiceReturnsBool(): void
    {
        if (!VmProcNiceNative::available()) {
            $this->markTestSkipped('/proc/self/autogroup unavailable');
        }

        $runtime = new Runtime();
        $fn = new proc_nice();
        $frame = $fn->getFrame($runtime->vmContext);
        $frame->returnVar = new VMVariable();
        $priority = new VMVariable();
        $priority->int(0);
        $frame->calledArgs = [$priority];
        $fn->execute($frame);
        $this->assertSame(VMVariable::TYPE_BOOLEAN, $frame->returnVar->resolveIndirect()->type);
    }

    public function testProcNiceArgumentCountErrorOnZeroArgs(): void
    {
        $runtime = new Runtime();
        $fn = new proc_nice();
        $frame = $fn->getFrame($runtime->vmContext);
        $frame->returnVar = new VMVariable();
        $frame->calledArgs = [];
        $this->expectException(\ArgumentCountError::class);
        $this->expectExceptionMessage('proc_nice() expects exactly 1 argument, 0 given');
        $fn->execute($frame);
    }

    public function testProcNiceTypeErrorOnArray(): void
    {
        $runtime = new Runtime();
        $fn = new proc_nice();
        $frame = $fn->getFrame($runtime->vmContext);
        $frame->returnVar = new VMVariable();
        $bad = new VMVariable();
        $bad->array(new HashTable());
        $frame->calledArgs = [$bad];
        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage(
            'proc_nice(): Argument #1 ($priority) must be of type int, array given'
        );
        $fn->execute($frame);
    }
}
