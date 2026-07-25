<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\pgsql\VmPgsqlConnection;
use PHPCompiler\ext\pgsql\VmPgsqlLob;
use PHPCompiler\ext\pgsql\VmPgsqlResult;
use PHPCompiler\ext\standard\VmSerialize;
use PHPCompiler\Runtime;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** @covers issue #23135 */
final class PgsqlSerializeDenyTest extends TestCase
{
    /** @return list<array{0: class-string, 1: string}> */
    public function opaqueClassProvider(): array
    {
        return [
            [VmPgsqlConnection::class, VmPgsqlConnection::CLASS_LC],
            [VmPgsqlResult::class, VmPgsqlResult::CLASS_LC],
            [VmPgsqlLob::class, VmPgsqlLob::CLASS_LC],
        ];
    }

    /** @dataProvider opaqueClassProvider */
    public function testSerializeOfPgsqlOpaqueObjectThrows(string $vmClass, string $classLc): void
    {
        $runtime = new Runtime();
        $vmClass::registerClass($runtime->vmContext);
        $display = $runtime->vmContext->classes[$classLc]->name;
        $object = new ObjectEntry($runtime->vmContext->classes[$classLc]);
        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($object);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Serialization of '{$display}' is not allowed");
        VmSerialize::serializeValue($runtime->vmContext, $var);
    }
}
