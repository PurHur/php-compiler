<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\dba\VmDbaConnection;
use PHPCompiler\ext\standard\VmSerialize;
use PHPCompiler\Runtime;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** @covers issue #23113 */
final class DbaSerializeDenyTest extends TestCase
{
    public function testSerializeOfDbaConnectionThrows(): void
    {
        $runtime = new Runtime();
        VmDbaConnection::registerClass($runtime->vmContext);
        $display = $runtime->vmContext->classes[VmDbaConnection::CLASS_LC]->name;
        $object = new ObjectEntry($runtime->vmContext->classes[VmDbaConnection::CLASS_LC]);
        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($object);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Serialization of '{$display}' is not allowed");
        VmSerialize::serializeValue($runtime->vmContext, $var);
    }
}
