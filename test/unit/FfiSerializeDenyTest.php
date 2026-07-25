<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\ffi\VmFFI;
use PHPCompiler\ext\standard\VmSerialize;
use PHPCompiler\Runtime;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** @covers issue #23133 */
final class FfiSerializeDenyTest extends TestCase
{
    /**
     * @return list<array{0: string}>
     */
    public static function deniedClassProvider(): array
    {
        return [
            [VmFFI::CLASS_LC],
            [VmFFI::CLASS_CDATA_LC],
            [VmFFI::CLASS_CTYPE_LC],
        ];
    }

    /** @dataProvider deniedClassProvider */
    public function testSerializeOfFfiFamilyThrows(string $classLc): void
    {
        $runtime = new Runtime();
        VmFFI::registerClass($runtime->vmContext);
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
