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
}
