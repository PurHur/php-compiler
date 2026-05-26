<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\random_int;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** VM builtin for random_int() (#2330). */
final class RandomIntBuiltinTest extends TestCase
{
    public function testInRange(): void
    {
        $runtime = new Runtime();
        $fn = new random_int();
        $frame = $fn->getFrame($runtime->vmContext);
        $minVar = new VMVariable();
        $minVar->int(10);
        $maxVar = new VMVariable();
        $maxVar->int(12);
        $frame->calledArgs = [$minVar, $maxVar];
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);
        $n = $frame->returnVar->resolveIndirect()->toInt();
        $this->assertGreaterThanOrEqual(10, $n);
        $this->assertLessThanOrEqual(12, $n);
    }

    public function testMinGreaterThanMaxThrows(): void
    {
        $runtime = new Runtime();
        $fn = new random_int();
        $frame = $fn->getFrame($runtime->vmContext);
        $minVar = new VMVariable();
        $minVar->int(5);
        $maxVar = new VMVariable();
        $maxVar->int(1);
        $frame->calledArgs = [$minVar, $maxVar];
        $frame->returnVar = new VMVariable();
        $this->expectException(\ValueError::class);
        $fn->execute($frame);
    }
}
