<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\ldap\VmLdapConnection;
use PHPCompiler\ext\ldap\VmLdapResult;
use PHPCompiler\ext\standard\VmSerialize;
use PHPCompiler\Runtime;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** @covers issue #23169 */
final class LdapSerializeDenyTest extends TestCase
{
    /** @return list<array{0: string}> */
    public function opaqueClassProvider(): array
    {
        return [
            [VmLdapConnection::CLASS_LC],
            [VmLdapResult::RESULT_CLASS_LC],
            [VmLdapResult::ENTRY_CLASS_LC],
        ];
    }

    /** @dataProvider opaqueClassProvider */
    public function testSerializeOfLdapOpaqueObjectThrows(string $classLc): void
    {
        $runtime = new Runtime();
        VmLdapConnection::registerClass($runtime->vmContext);
        VmLdapResult::registerClasses($runtime->vmContext);
        $this->assertArrayHasKey($classLc, $runtime->vmContext->classes);
        $display = $runtime->vmContext->classes[$classLc]->name;
        $object = new ObjectEntry($runtime->vmContext->classes[$classLc]);
        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($object);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Serialization of '{$display}' is not allowed");
        VmSerialize::serializeValue($runtime->vmContext, $var);
    }
}
