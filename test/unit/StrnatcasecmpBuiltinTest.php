<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\strnatcasecmp;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** VM builtin for strnatcasecmp(). */
final class StrnatcasecmpBuiltinTest extends TestCase
{
    public function testCaseInsensitiveNaturalOrder(): void
    {
        $runtime = new Runtime();
        $fn = new strnatcasecmp();
        $frame = $fn->getFrame($runtime->vmContext);
        $a = new VMVariable();
        $a->string('Img12');
        $b = new VMVariable();
        $b->string('img2');
        $frame->calledArgs = [$a, $b];
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);
        $this->assertGreaterThan(0, $frame->returnVar->toInt());
    }

    public function testEqualIgnoringCase(): void
    {
        $runtime = new Runtime();
        $fn = new strnatcasecmp();
        $frame = $fn->getFrame($runtime->vmContext);
        $a = new VMVariable();
        $a->string('foo10');
        $b = new VMVariable();
        $b->string('FOO10');
        $frame->calledArgs = [$a, $b];
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);
        $this->assertSame(0, $frame->returnVar->toInt());
    }
}
