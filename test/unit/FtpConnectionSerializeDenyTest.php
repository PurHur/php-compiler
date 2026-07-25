<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\ftp\VmFtpConnection;
use PHPCompiler\ext\standard\VmSerialize;
use PHPCompiler\Runtime;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** @covers issue #23134 */
final class FtpConnectionSerializeDenyTest extends TestCase
{
    public function testSerializeOfFtpConnectionObjectThrows(): void
    {
        $runtime = new Runtime();
        VmFtpConnection::registerClass($runtime->vmContext);
        $object = new ObjectEntry($runtime->vmContext->classes[VmFtpConnection::CLASS_LC]);
        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($object);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Serialization of 'FTP\\Connection' is not allowed");
        VmSerialize::serializeValue($runtime->vmContext, $var);
    }
}
