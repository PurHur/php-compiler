<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;
use PHPCompiler\ext\standard\VmIni;
use PHPCompiler\ext\standard\VmSerialize;
use PHPCompiler\ext\standard\VmSerializeFormat;

/** serialize_precision INI + serialize() float parity (issues #7100, #7103). */
final class SerializePrecisionIniTest extends TestCase
{
    public function testVmIniDefaultSerializePrecision(): void
    {
        $this->assertSame('-1', VmIni::getSerializePrecision());
        $this->assertSame(-1, VmIni::parseSerializePrecision('-1'));
    }

    public function testVmSerializeRespectsPrecision(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;
        VmIni::set($ctx, 'serialize_precision', '2');
        $this->assertSame('d:1.2;', VmSerialize::serializeExported(1.239));
        VmIni::set($ctx, 'serialize_precision', '-1');
    }

    public function testVmSerializeFormatDoubleDefaultDtoa(): void
    {
        $this->assertSame('1.239', VmSerializeFormat::formatDoubleWithPrecision(1.239, -1));
        $this->assertSame('0.30000000000000004', VmSerializeFormat::formatDoubleWithPrecision(0.1 + 0.2, -1));
    }

    public function testVmSerializeNoHostIniGetInSource(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmSerialize.php');
        $this->assertStringNotContainsString('ini_get', $source);
        $this->assertStringNotContainsString('ini_set', $source);
        $this->assertStringContainsString('VmSerializeFormat', $source);
        $this->assertStringContainsString('VmUnserializeFormat', $source);
        $this->assertDoesNotMatchRegularExpression('/@\\\\unserialize\\s*\\(/', $source);
    }
}
