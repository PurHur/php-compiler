<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit\Web;

use PHPCompiler\ext\standard\VmFastCgi;
use PHPCompiler\VM\OutputBuffer;
use PHPUnit\Framework\TestCase;

final class FastCgiFinishRequestTest extends TestCase
{
    protected function tearDown(): void
    {
        VmFastCgi::clearFastCgiRequestActive();
        OutputBuffer::reset();
    }

    public function testFinishRequestReturnsFalseWhenNotFastCgi(): void
    {
        VmFastCgi::clearFastCgiRequestActive();
        self::assertFalse(VmFastCgi::finishRequest());
    }

    public function testFinishRequestReturnsTrueAndFlushesWhenFastCgiActive(): void
    {
        VmFastCgi::markFastCgiRequestActive();
        OutputBuffer::start();
        OutputBuffer::append('buffered');
        self::assertTrue(VmFastCgi::finishRequest());
        self::assertSame(0, OutputBuffer::getLevel());
    }
}
