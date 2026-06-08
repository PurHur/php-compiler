<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit\Ext;

use PHPCompiler\ext\standard\VmSerialize;
use PHPUnit\Framework\TestCase;

/** serialize()/unserialize() enum wire format (#6131). */
final class VmSerializeEnumCaseTest extends TestCase
{
    public function testEncodeEnumCaseLiteral(): void
    {
        $this->assertSame('E:3:"U:A";', VmSerialize::encodeEnumCaseLiteral('U', 'A'));
        $this->assertSame('E:9:"My\\Enum:A";', VmSerialize::encodeEnumCaseLiteral('My\\Enum', 'A'));
    }

    public function testParseEnumCasePayload(): void
    {
        $this->assertSame(['U', 'A'], VmSerialize::parseEnumCasePayload('E:3:"U:A";'));
        $this->assertSame(['My\\Status', 'Active'], VmSerialize::parseEnumCasePayload('E:16:"My\\Status:Active";'));
        $this->assertNull(VmSerialize::parseEnumCasePayload('E:3:"U:A"'));
        $this->assertNull(VmSerialize::parseEnumCasePayload('O:1:"U":0:{}'));
    }
}
