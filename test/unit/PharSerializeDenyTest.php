<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\phar\VmPhar;
use PHPCompiler\ext\phar\VmPharData;
use PHPCompiler\ext\phar\VmPharFileInfo;
use PHPCompiler\ext\standard\VmSerialize;
use PHPCompiler\Runtime;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** @covers issue #23154 */
final class PharSerializeDenyTest extends TestCase
{
    /**
     * @return list<array{0: string}>
     */
    public static function deniedClassProvider(): array
    {
        return [
            [VmPhar::CLASS_LC],
            [VmPharData::CLASS_LC],
            [VmPharFileInfo::CLASS_LC],
        ];
    }

    /** @dataProvider deniedClassProvider */
    public function testSerializeOfPharFamilyThrows(string $classLc): void
    {
        $runtime = new Runtime();
        \PHPCompiler\ext\phar\BuiltinClasses::register($runtime->vmContext);
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
