<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\enchant\VmEnchantBroker;
use PHPCompiler\ext\enchant\VmEnchantDictionary;
use PHPCompiler\ext\standard\VmSerialize;
use PHPCompiler\Runtime;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** @covers issue #23112 */
final class EnchantSerializeDenyTest extends TestCase
{
    /**
     * @return list<array{0: string}>
     */
    public static function deniedClassProvider(): array
    {
        return [
            [VmEnchantBroker::CLASS_LC],
            [VmEnchantDictionary::CLASS_LC],
        ];
    }

    /** @dataProvider deniedClassProvider */
    public function testSerializeOfEnchantFamilyThrows(string $classLc): void
    {
        $runtime = new Runtime();
        VmEnchantBroker::registerClass($runtime->vmContext);
        VmEnchantDictionary::registerClass($runtime->vmContext);
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
