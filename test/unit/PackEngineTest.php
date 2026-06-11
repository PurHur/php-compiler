<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPUnit\Framework\TestCase;

/** PackEngine parity with Zend pack() (issue #5231). */
final class PackEngineTest extends TestCase
{
    public function testBasicFormatsMatchZend(): void
    {
        $this->assertSame(\pack('c', 65), PackEngine::pack('c', [65]));
        $this->assertSame(\pack('n', 0x1234), PackEngine::pack('n', [0x1234]));
        $this->assertSame(\pack('a3', 'hi'), PackEngine::pack('a3', ['hi']));
        $this->assertSame(\pack('H4', 'dead'), PackEngine::pack('H4', ['dead']));
        $this->assertSame(\pack('i', 1), PackEngine::pack('i', [1]));
        $this->assertSame(\strlen(\pack('i', 1)), \strlen(PackEngine::pack('i', [1])));
    }

    public function testEmptyFormat(): void
    {
        $this->assertSame('', PackEngine::pack('', []));
    }

    public function testPaddingAndAt(): void
    {
        $this->assertSame(\pack('x2c', 1), PackEngine::pack('x2c', [1]));
        $this->assertSame(\pack('@4c', 9), PackEngine::pack('@4c', [9]));
    }

    public function testInvalidFormatThrowsValueError(): void
    {
        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('Type !: unknown format code');
        PackEngine::pack('!', [1]);
    }

    public function testTooFewArgumentsThrowsValueError(): void
    {
        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('Type c: too few arguments');
        PackEngine::pack('cc', [1]);
    }
}
