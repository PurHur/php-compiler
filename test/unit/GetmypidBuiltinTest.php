<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\getmypid;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** VM builtin for getmypid() (#2195). */
final class GetmypidBuiltinTest extends TestCase
{
    public function testReturnsHostPid(): void
    {
        $runtime = new Runtime();
        $fn = new getmypid();
        $frame = $fn->getFrame($runtime->vmContext);
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);
        $this->assertSame(\getmypid(), $frame->returnVar->resolveIndirect()->toInt());
    }
}
