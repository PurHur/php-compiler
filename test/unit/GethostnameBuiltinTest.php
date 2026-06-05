<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\gethostname;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** VM builtin for gethostname() (#3465). */
final class GethostnameBuiltinTest extends TestCase
{
    public function testTooManyArgsThrowsArgumentCountError(): void
    {
        $runtime = new Runtime();
        $fn = new gethostname();
        $frame = $fn->getFrame($runtime->vmContext);
        $frame->calledArgs[] = new VMVariable();
        $frame->calledArgs[0]->int(1);
        $this->expectException(\ArgumentCountError::class);
        $this->expectExceptionMessage('gethostname() expects exactly 0 arguments, 1 given');
        $fn->execute($frame);
    }

    public function testReturnsHostName(): void
    {
        $runtime = new Runtime();
        $fn = new gethostname();
        $frame = $fn->getFrame($runtime->vmContext);
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);
        $resolved = $frame->returnVar->resolveIndirect();
        $host = \gethostname();
        if (false === $host) {
            $this->assertTrue($resolved->toBool() === false);
        } else {
            $this->assertSame($host, $resolved->toString());
        }
    }
}
