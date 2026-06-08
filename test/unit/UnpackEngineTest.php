<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPUnit\Framework\TestCase;

/** UnpackEngine parity with Zend unpack() (issues #5442, #4674). */
final class UnpackEngineTest extends TestCase
{
    public function testNamedFormatParsesLikeZend(): void
    {
        $specs = UnpackEngine::parseFormat('Nwidth/Nheight');
        $this->assertNotNull($specs);
        $this->assertCount(2, $specs);
        $this->assertSame('N', $specs[0]['code']);
        $this->assertSame('width', $specs[0]['name']);
        $this->assertSame('N', $specs[1]['code']);
        $this->assertSame('height', $specs[1]['name']);
    }

    public function testNamedUnpackMatchesZend(): void
    {
        $data = PackEngine::pack('NN', [640, 480]);
        $expected = \unpack('Nwidth/Nheight', $data);
        $actual = UnpackEngine::unpack('Nwidth/Nheight', $data);
        $this->assertSame($expected, $actual);
    }

    public function testSmokeFormatsMatchZend(): void
    {
        $this->assertSame(\unpack('C3', 'ABC'), UnpackEngine::unpack('C3', 'ABC'));
        $this->assertSame(\unpack('n', PackEngine::pack('n', [0x1234])), UnpackEngine::unpack('n', PackEngine::pack('n', [0x1234])));
        $this->assertSame(\unpack('N', PackEngine::pack('N', [42])), UnpackEngine::unpack('N', PackEngine::pack('N', [42])));
    }
}
