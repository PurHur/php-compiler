<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\gethostname;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** VM builtin for gethostname() (#3465). */
final class GethostnameBuiltinTest extends TestCase
{
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
