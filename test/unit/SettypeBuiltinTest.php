<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmSettype;
use PHPCompiler\Frame;
use PHPCompiler\Runtime;
use PHPCompiler\VM\BuiltinClasses;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** Issue #3112: settype() VM builtin. */
final class SettypeBuiltinTest extends TestCase
{
    public function testInvalidTypeThrowsValueError(): void
    {
        $var = new VMVariable();
        $var->int(1);

        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('settype(): Argument #2 ($type) must be a valid type');
        VmSettype::apply($var, 'not-a-type');
    }

    public function testSettypeToObjectWrapsIntScalar(): void
    {
        $ctx = new Context(new Runtime());
        BuiltinClasses::register($ctx);
        $frame = $this->getMockBuilder(Frame::class)->disableOriginalConstructor()->getMock();
        $frame->vmContext = $ctx;

        $var = new VMVariable();
        $var->int(1);
        VmSettype::apply($var, 'object', $frame);

        $resolved = $var->resolveIndirect();
        $this->assertSame(VMVariable::TYPE_OBJECT, $resolved->type);
        $object = $resolved->toObject();
        $this->assertSame('stdClass', $object->class->name);
        $this->assertArrayHasKey('scalar', $object->getRawProperties());
        $this->assertSame(1, $object->getRawProperties()['scalar']->toInt());
    }

    public function testSettypeToStringOnBackedEnumCaseThrowsError(): void
    {
        $enum = new \PHPCompiler\VM\ClassEntry('Es');
        $enum->isEnum = true;
        $enum->backedType = 'string';

        $backing = new VMVariable();
        $backing->string('a');
        $case = EnumCaseSupport::createCase($enum, 'A', $backing);
        $var = new VMVariable();
        $var->copyFrom($case);

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Object of class Es could not be converted to string');
        VmSettype::apply($var, 'string');
    }

    public function testSettypeToStringInvokesToString(): void
    {
        $ctx = new Context(new Runtime());
        BuiltinClasses::register($ctx);
        $frame = $this->getMockBuilder(Frame::class)->disableOriginalConstructor()->getMock();
        $frame->vmContext = $ctx;
        $vm = $this->getMockBuilder(VM::class)->disableOriginalConstructor()->getMock();
        $vm->method('castObjectToString')->willReturn('ok');
        $ctx->runtime->vm = $vm;

        $class = new \PHPCompiler\VM\ClassEntry('WithToString');
        $object = new \PHPCompiler\VM\ObjectEntry($class);
        $object->constructed = true;
        $var = new VMVariable();
        $var->object($object);

        VmSettype::apply($var, 'string', $frame);

        $this->assertSame('ok', $var->resolveIndirect()->toString());
    }

    /** Issue #10690: settype($obj, 'int') on plain object — E_WARNING + 1 (ext/standard/type.c). */
    public function testSettypePlainObjectToIntEmitsWarningAndOne(): void
    {
        $ctx = new Context(new Runtime());
        BuiltinClasses::register($ctx);
        $frame = $this->getMockBuilder(Frame::class)->disableOriginalConstructor()->getMock();
        $frame->vmContext = $ctx;

        $object = new \PHPCompiler\VM\ObjectEntry($ctx->classes['stdclass']);
        $object->constructed = true;
        $var = new VMVariable();
        $var->object($object);

        VmSettype::apply($var, 'int', $frame);

        $this->assertSame(VMVariable::TYPE_INTEGER, $var->resolveIndirect()->type);
        $this->assertSame(1, $var->resolveIndirect()->toInt());
    }
}
