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

    /** Issue #10293: settype($array,'string') — Array to string conversion warning (ext/standard/type.c). */
    public function testSettypeArrayToStringEmitsWarning(): void
    {
        $ctx = new Context(new Runtime());
        BuiltinClasses::register($ctx);
        $frame = $this->getMockBuilder(Frame::class)->disableOriginalConstructor()->getMock();
        $frame->vmContext = $ctx;
        $frame->scriptPath = 'test.php';
        $frame->callSiteLine = 3;
        $frame->parent = null;

        $var = new VMVariable();
        $var->array(new \PHPCompiler\VM\HashTable());
        VmSettype::apply($var, 'string', $frame);

        $this->assertSame('Array', $var->resolveIndirect()->toString());
        $last = $ctx->errors->getLastErrorVariable()->resolveIndirect();
        $this->assertSame(VMVariable::TYPE_ARRAY, $last->type);
        $msg = $last->toArray()->find('message');
        $this->assertNotNull($msg);
        $this->assertStringContainsString(
            'Array to string conversion',
            $msg->resolveIndirect()->toString()
        );
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

    /** Issue #10691: settype($resource, 'string') → 'Resource id #N' (ext/standard/type.c). */
    public function testSettypeResourceToString(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;
        BuiltinClasses::register($ctx);
        $frame = $this->getMockBuilder(Frame::class)->disableOriginalConstructor()->getMock();
        $frame->vmContext = $ctx;

        $var = new VMVariable();
        $handle = \PHPCompiler\ext\standard\VmFs::fopen('php://memory', 'r+');
        $this->assertIsInt($handle);
        $var->streamHandle($handle, $ctx);

        VmSettype::apply($var, 'string', $frame);

        $resolved = $var->resolveIndirect();
        $this->assertSame(VMVariable::TYPE_STRING, $resolved->type);
        $this->assertSame('Resource id #'.$handle, $resolved->toString());
    }

    /** Issue #10812: settype($resource, 'int') → resource handle id (ext/standard/type.c). */
    public function testSettypeResourceToInt(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;
        BuiltinClasses::register($ctx);
        $frame = $this->getMockBuilder(Frame::class)->disableOriginalConstructor()->getMock();
        $frame->vmContext = $ctx;

        $var = new VMVariable();
        $handle = \PHPCompiler\ext\standard\VmFs::fopen('php://memory', 'r+');
        $this->assertIsInt($handle);
        $var->streamHandle($handle, $ctx);

        VmSettype::apply($var, 'int', $frame);

        $resolved = $var->resolveIndirect();
        $this->assertSame(VMVariable::TYPE_INTEGER, $resolved->type);
        $this->assertSame($handle, $resolved->toInt());
    }
}
