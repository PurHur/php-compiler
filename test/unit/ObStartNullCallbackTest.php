<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\ob_start;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/**
 * ob_start(?callable $callback = null) — null means no user filter (#30121).
 */
final class ObStartNullCallbackTest extends TestCase
{
    public function testNullCallbackReturnsTrueAndStartsBuffer(): void
    {
        $runtime = new Runtime();
        $builtin = new ob_start();
        $frame = $builtin->getFrame($runtime->vmContext);
        $frame->returnVar = new VMVariable();

        $nullArg = new VMVariable();
        $nullArg->null();
        $frame->calledArgs = [$nullArg];
        $builtin->execute($frame);

        $this->assertTrue($frame->returnVar->toBool());
        $this->assertGreaterThan(0, \PHPCompiler\VM\OutputBuffer::getLevel());
        \PHPCompiler\VM\OutputBuffer::endClean();
    }

    public function testOmittedCallbackReturnsTrue(): void
    {
        $runtime = new Runtime();
        $builtin = new ob_start();
        $frame = $builtin->getFrame($runtime->vmContext);
        $frame->returnVar = new VMVariable();
        $frame->calledArgs = [];
        $builtin->execute($frame);

        $this->assertTrue($frame->returnVar->toBool());
        $this->assertGreaterThan(0, \PHPCompiler\VM\OutputBuffer::getLevel());
        \PHPCompiler\VM\OutputBuffer::endClean();
    }
}
