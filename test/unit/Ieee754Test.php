<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPUnit\Framework\TestCase;

/** Pure-PHP IEEE754 for pack()/unpack() self-host path (#4674 phase 2). */
final class Ieee754Test extends TestCase
{
    public function testFloat32RoundTripMatchesZend(): void
    {
        foreach ([0.0, -0.0, 1.0, -1.0, 3.14159, 1.0e-10, 1.0e10] as $value) {
            $le = Ieee754::encodeFloat32($value, true);
            $this->assertSame(\pack('g', $value), $le);
            $this->assertEqualsWithDelta($value, Ieee754::decodeFloat32($le, true), 1.0e-6);

            $be = Ieee754::encodeFloat32($value, false);
            $this->assertSame(\pack('G', $value), $be);
            $this->assertEqualsWithDelta($value, Ieee754::decodeFloat32($be, false), 1.0e-6);

            $native = Ieee754::encodeFloat32($value, 0 !== \unpack('S', "\x00\x01")[1]);
            $this->assertSame(\pack('f', $value), $native);
            $this->assertEqualsWithDelta($value, Ieee754::decodeFloat32($native, 0 !== \unpack('S', "\x00\x01")[1]), 1.0e-6);
        }
    }

    public function testFloat64RoundTripMatchesZend(): void
    {
        foreach ([0.0, 42.5, -123.456789, 1.0e100] as $value) {
            $le = Ieee754::encodeFloat64($value, true);
            $this->assertSame(\pack('e', $value), $le);
            $this->assertEqualsWithDelta($value, Ieee754::decodeFloat64($le, true), 1.0e-10);

            $be = Ieee754::encodeFloat64($value, false);
            $this->assertSame(\pack('E', $value), $be);
            $this->assertEqualsWithDelta($value, Ieee754::decodeFloat64($be, false), 1.0e-10);

            $native = Ieee754::encodeFloat64($value, 0 !== \unpack('S', "\x00\x01")[1]);
            $this->assertSame(\pack('d', $value), $native);
            $this->assertEqualsWithDelta($value, Ieee754::decodeFloat64($native, 0 !== \unpack('S', "\x00\x01")[1]), 1.0e-10);
        }
    }

    public function testSpecialValues(): void
    {
        $this->assertTrue(is_nan(Ieee754::decodeFloat32(Ieee754::encodeFloat32(NAN, true), true)));
        $this->assertSame(INF, Ieee754::decodeFloat32(Ieee754::encodeFloat32(INF, true), true));
        $this->assertSame(-INF, Ieee754::decodeFloat32(Ieee754::encodeFloat32(-INF, true), true));
    }

    /** NestedJIT pack must not call \round() — inline half-up (#26862; MathRound always ensureBridge #28913). */
    public function testEncodeAvoidsHostRoundBuiltin(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/Ieee754.php');
        $this->assertStringNotContainsString('\round($', $source);
        $this->assertStringContainsString('#26862', $source);
        $this->assertStringContainsString('(int) ($scaled + 0.5)', $source);
    }
}
