<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;
use PHPCompiler\ext\standard\VmIni;
use PHPCompiler\ext\standard\VmSerialize;

/** serialize_precision INI + serialize() float parity (issue #7100). */
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
}
