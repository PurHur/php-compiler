<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\sys_getloadavg;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** VM builtin for sys_getloadavg() (#3464). */
final class SysGetloadavgBuiltinTest extends TestCase
{
    public function testReturnsLoadArrayOrFalse(): void
    {
        $runtime = new Runtime();
        $fn = new sys_getloadavg();
        $frame = $fn->getFrame($runtime->vmContext);
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);
        $resolved = $frame->returnVar->resolveIndirect();
        $expected = \sys_getloadavg();
        if (false === $expected) {
            $this->assertTrue($resolved->toBool() === false);
        } else {
            $this->assertSame(VMVariable::TYPE_ARRAY, $resolved->type);
            $ht = $resolved->toArray();
            $this->assertSame(3, $ht->getNumElements());
            for ($i = 0; $i < 3; ++$i) {
                $elem = $ht->findIndex($i);
                $this->assertNotNull($elem);
                $this->assertSame((float) $expected[$i], $elem->resolveIndirect()->toFloat());
            }
        }
    }

    public function testTooManyArgsThrowsArgumentCountError(): void
    {
        $runtime = new Runtime();
        $fn = new sys_getloadavg();
        $frame = $fn->getFrame($runtime->vmContext);
        $frame->calledArgs[] = new VMVariable();
        $frame->calledArgs[0]->int(1);
        $this->expectException(\ArgumentCountError::class);
        $fn->execute($frame);
    }
}
