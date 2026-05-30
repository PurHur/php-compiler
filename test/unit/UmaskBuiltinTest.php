<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\umask_;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** VM builtin for umask() (#3226). */
final class UmaskBuiltinTest extends TestCase
{
    public function testGetSetRestoreMatchesHost(): void
    {
        $saved = (int) \umask();
        $runtime = new Runtime();
        $fn = new umask_();
        $frame = $fn->getFrame($runtime->vmContext);
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);
        $this->assertSame($saved, $frame->returnVar->resolveIndirect()->toInt());

        $frame = $fn->getFrame($runtime->vmContext);
        $frame->returnVar = new VMVariable();
        $maskVar = new VMVariable();
        $maskVar->int(0011);
        $frame->calledArgs = [$maskVar];
        $fn->execute($frame);
        $this->assertSame($saved, $frame->returnVar->resolveIndirect()->toInt());
        $this->assertSame(0011, (int) \umask());

        $frame = $fn->getFrame($runtime->vmContext);
        $frame->returnVar = new VMVariable();
        $restoreVar = new VMVariable();
        $restoreVar->int($saved);
        $frame->calledArgs = [$restoreVar];
        $fn->execute($frame);
        $this->assertSame(0011, $frame->returnVar->resolveIndirect()->toInt());

        \umask($saved);
    }
}
