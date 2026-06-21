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

    public function testFloatFormatsMatchZend(): void
    {
        foreach ([0.0, 1.5, -2.25, 3.14159] as $value) {
            foreach (['f', 'd', 'g', 'G', 'e', 'E'] as $code) {
                $packed = PackEngine::pack($code, [$value]);
                $this->assertSame(\unpack($code, $packed), UnpackEngine::unpack($code, $packed));
            }
        }
    }

    public function testSlashRepeatFormatsMatchZend(): void
    {
        $this->assertSame(\unpack('C2/C', 'abc'), UnpackEngine::unpack('C2/C', 'abc'));
        $this->assertSame(\unpack('H2/H', 'abcd'), UnpackEngine::unpack('H2/H', 'abcd'));
        $this->assertSame(\unpack('C2/C2', 'abcd'), UnpackEngine::unpack('C2/C2', 'abcd'));
    }

    public function testRepeatedFormatEmbeddedNameKeysMatchZend(): void
    {
        $this->assertSame(\unpack('a2a2', 'abcd'), UnpackEngine::unpack('a2a2', 'abcd'));
        $this->assertSame(\unpack('A2A2', 'abcd'), UnpackEngine::unpack('A2A2', 'abcd'));
        $this->assertSame(\unpack('Z2Z2', "a\x00b\x00"), UnpackEngine::unpack('Z2Z2', "a\x00b\x00"));
        $this->assertSame(\unpack('h2h2', 'abcd'), UnpackEngine::unpack('h2h2', 'abcd'));
        $this->assertSame(\unpack('C2foo', 'AB'), UnpackEngine::unpack('C2foo', 'AB'));
    }

    public function testUnpackEngineDoesNotUseHostFloatUnpack(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/UnpackEngine.php');
        $this->assertStringNotContainsString("\\unpack('f'", $source);
        $this->assertStringNotContainsString("\\unpack('d'", $source);
        $this->assertStringContainsString('Ieee754::decodeFloat32', $source);
    }
}
