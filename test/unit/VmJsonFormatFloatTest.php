<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;
use PHPCompiler\ext\standard\VmJsonFlags;
use PHPCompiler\ext\standard\VmJsonFormat;

/** json_encode() float formatting parity (#10797). */
final class VmJsonFormatFloatTest extends TestCase
{
    public function testBinaryFractionUsesDtoa(): void
    {
        $encoded = VmJsonFormat::encodeExported(0.1 + 0.2, 0);
        $this->assertSame('0.30000000000000004', $encoded);
    }

    public function testPreserveZeroFractionOnWholeFloat(): void
    {
        $flags = VmJsonFlags::PRESERVE_ZERO_FRACTION;
        $this->assertSame('1.0', VmJsonFormat::encodeExported(1.0, $flags));
        $this->assertSame('42.0', VmJsonFormat::encodeExported(42.0, $flags));
    }

    public function testWholeFloatWithoutPreserveFlag(): void
    {
        $this->assertSame('42', VmJsonFormat::encodeExported(42.0, 0));
    }
}
