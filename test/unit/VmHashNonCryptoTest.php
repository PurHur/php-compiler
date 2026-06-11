<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmHash;
use PHPCompiler\ext\standard\VmHashNative;
use PHPCompiler\ext\standard\VmHashNonCrypto;
use PHPUnit\Framework\TestCase;

/** hash() non-crypto digests (issue #4644). */
final class VmHashNonCryptoTest extends TestCase
{
    /** @return iterable<string, array{0: string, 1: string}> */
    public static function nonCryptoProvider(): iterable
    {
        yield 'crc32b' => ['crc32b', '352441c2'];
        yield 'crc32' => ['crc32', '73bb8c64'];
        yield 'adler32' => ['adler32', '024d0127'];
        yield 'fnv132' => ['fnv132', '439c2f4b'];
        yield 'fnv1a32' => ['fnv1a32', '1a47e90b'];
    }

    /** @dataProvider nonCryptoProvider */
    public function testVmHashNativeMatchesZend(string $algo, string $expectedHex): void
    {
        $this->assertSame($expectedHex, VmHashNative::hash($algo, 'abc'));
        $this->assertSame($expectedHex, VmHashNative::hash(\strtoupper($algo), 'abc'));
    }

    /** @dataProvider nonCryptoProvider */
    public function testVmHashFacadeMatchesZend(string $algo, string $expectedHex): void
    {
        $this->assertSame($expectedHex, VmHash::hash($algo, 'abc'));
    }

    public function testRawOutputFourBytes(): void
    {
        $expected = \hash('crc32b', 'abc', true);
        $actual = VmHashNative::hash('crc32b', 'abc', true);
        $this->assertSame($expected, $actual);
        $this->assertSame(4, \strlen($actual));
    }

    public function testNonCryptoDirectHelpersMatchZend(): void
    {
        $this->assertSame(0x352441c2, VmHashNonCrypto::crc32b('abc') & 0xFFFFFFFF);
        $this->assertSame(0x73bb8c64, \hexdec(VmHashNative::hash('crc32', 'abc')) & 0xFFFFFFFF);
        $this->assertSame(0x024d0127, VmHashNonCrypto::adler32('abc') & 0xFFFFFFFF);
        $this->assertSame(0x439c2f4b, VmHashNonCrypto::fnv132('abc') & 0xFFFFFFFF);
        $this->assertSame(0x1a47e90b, VmHashNonCrypto::fnv1a32('abc') & 0xFFFFFFFF);
    }
}
