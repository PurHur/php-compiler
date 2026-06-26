<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\OpCode;
use PHPCompiler\Runtime;
use PHPCompiler\VM\Context as VMContext;
use PHPCompiler\VM\Variable;
use PHPCompiler\VM\VmTryCatch;
use PHPUnit\Framework\TestCase;

/** VmTryCatch SSOT for catch-arm type matching (#9663). */
final class VmTryCatchTest extends TestCase
{
    public function testEmptyEncodedTypesMatch(): void
    {
        $ctx = new VMContext(new Runtime(Runtime::MODE_NORMAL));
        $op = new OpCode(OpCode::TYPE_CATCH);
        $op->catchTypes = '';
        $thrown = new Variable();
        $thrown->null();

        $this->assertTrue(VmTryCatch::encodedTypesMatchOpcode($op, $thrown, $ctx));
        $this->assertTrue(VmTryCatch::encodedTypesMatchVariable('', $thrown, $ctx));
    }

    public function testNonObjectDoesNotMatchTypedCatch(): void
    {
        $ctx = new VMContext(new Runtime(Runtime::MODE_NORMAL));
        $op = new OpCode(OpCode::TYPE_CATCH);
        $op->catchTypes = 'exception';
        $thrown = new Variable();
        $thrown->int(1);

        $this->assertFalse(VmTryCatch::encodedTypesMatchOpcode($op, $thrown, $ctx));
        $this->assertFalse(VmTryCatch::encodedTypesMatchVariable('exception', $thrown, $ctx));
    }
}
