<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\uniqid;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** VM builtin for uniqid() (#2219). */
final class UniqidBuiltinTest extends TestCase
{
    public function testMoreEntropyMatchesHostShape(): void
    {
        $runtime = new Runtime();
        $fn = new uniqid();
        $frame = $fn->getFrame($runtime->vmContext);
        $prefix = new VMVariable();
        $prefix->string('');
        $entropy = new VMVariable();
        $entropy->bool(true);
        $frame->calledArgs = [$prefix, $entropy];
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);
        $got = $frame->returnVar->resolveIndirect()->toString();
        $this->assertSame(23, \strlen($got));
        $this->assertMatchesRegularExpression('/^[0-9a-f]+\.[0-9a-f]{8}$/', $got);
    }
}
