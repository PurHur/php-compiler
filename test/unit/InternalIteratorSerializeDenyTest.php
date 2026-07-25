<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\spl\InternalIteratorBuiltin;
use PHPCompiler\ext\standard\VmSerialize;
use PHPCompiler\Runtime;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** @covers issue #23167 */
final class InternalIteratorSerializeDenyTest extends TestCase
{
    public function testSerializeOfInternalIteratorThrows(): void
    {
        $runtime = new Runtime();
        InternalIteratorBuiltin::registerClass($runtime->vmContext);
        $this->assertArrayHasKey(InternalIteratorBuiltin::CLASS_LC, $runtime->vmContext->classes);
        $display = $runtime->vmContext->classes[InternalIteratorBuiltin::CLASS_LC]->name;
        $object = new ObjectEntry($runtime->vmContext->classes[InternalIteratorBuiltin::CLASS_LC]);
        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($object);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Serialization of '{$display}' is not allowed");
        VmSerialize::serializeValue($runtime->vmContext, $var);
    }
}
