<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\uniqid;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** VM builtin for uniqid() (#2219). */
final class UniqidBuiltinTest extends TestCase
{
    public function testDefaultAndPrefix(): void
    {
        $runtime = new Runtime();
        $fn = new uniqid();
        $frame = $fn->getFrame($runtime->vmContext);
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);
        $id = $frame->returnVar->resolveIndirect()->toString();
        $this->assertSame(13, strlen($id));

        $frame2 = $fn->getFrame($runtime->vmContext);
        $pfx = new VMVariable();
        $pfx->string('pfx_');
        $frame2->calledArgs = [$pfx];
        $frame2->returnVar = new VMVariable();
        $fn->execute($frame2);
        $this->assertStringStartsWith('pfx_', $frame2->returnVar->resolveIndirect()->toString());
    }

    public function testMoreEntropyLength(): void
    {
        $runtime = new Runtime();
        $fn = new uniqid();
        $frame = $fn->getFrame($runtime->vmContext);
        $empty = new VMVariable();
        $empty->string('');
        $entropy = new VMVariable();
        $entropy->bool(true);
        $frame->calledArgs = [$empty, $entropy];
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);
        $id = $frame->returnVar->resolveIndirect()->toString();
        $this->assertGreaterThanOrEqual(22, strlen($id));
        $this->assertStringContainsString('.', $id);
    }
}
