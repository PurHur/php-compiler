<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Runtime;
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
        $this->assertSame(\pack('H', '4142'), PackEngine::pack('H', ['4142']));
        $this->assertSame(\pack('h', '4142'), PackEngine::pack('h', ['4142']));
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

    public function testFloatFormatsMatchZend(): void
    {
        foreach ([0.0, 1.5, -2.25, 3.14159] as $value) {
            $this->assertSame(\pack('f', $value), PackEngine::pack('f', [$value]));
            $this->assertSame(\pack('d', $value), PackEngine::pack('d', [$value]));
            $this->assertSame(\pack('g', $value), PackEngine::pack('g', [$value]));
            $this->assertSame(\pack('e', $value), PackEngine::pack('e', [$value]));
        }
    }

    public function testPackEngineDoesNotUseHostFloatPack(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/PackEngine.php');
        $this->assertStringNotContainsString("\\pack('f'", $source);
        $this->assertStringNotContainsString("\\pack('d'", $source);
        $this->assertStringContainsString('Ieee754::encodeFloat32', $source);
    }

    /** Self-host spine include (#11177): compile-time fold must tolerate null CFG operands. */
    public function testPackEngineSourceCompiles(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompileFile(__DIR__.'/../../ext/standard/PackEngine.php');
        $this->assertNotNull($block);
    }
}
