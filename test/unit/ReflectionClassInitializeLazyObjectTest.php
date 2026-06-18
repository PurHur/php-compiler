<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Runtime;
use PHPCompiler\VM\Builtin\ReflectionClassInitializeLazyObject;
use PHPCompiler\VM\BuiltinClasses;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\ClassProperty;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\LazyObjectSupport;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** Issue #7054: ReflectionClass::initializeLazyObject() VM builtin. */
final class ReflectionClassInitializeLazyObjectTest extends TestCase
{
    public function testMethodRegisteredOnBuiltinReflectionClass(): void
    {
        $ctx = new Context(new Runtime());
        BuiltinClasses::register($ctx);

        $this->assertArrayHasKey('initializelazyobject', $ctx->classes['reflectionclass']->methods);
    }

    public function testInitializeLazyObjectReturnsPlainObjectUnchanged(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;
        BuiltinClasses::register($ctx);

        $class = $this->registerSvcClass($ctx);
        $plain = new ObjectEntry($class);
        $plain->constructed = true;
        $plain->getProperty('id')->string('plain');

        $returned = $this->invokeInitializeLazyObject($ctx, $plain);

        $this->assertSame($plain, $returned);
        $this->assertSame('plain', $returned->getProperty('id')->resolveIndirect()->toString());
    }

    public function testInitializeLazyObjectMarksGhostWithoutInitializerInitialized(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;
        BuiltinClasses::register($ctx);

        $class = $this->registerSvcClass($ctx);
        $ghost = LazyObjectSupport::createGhost($class, null);
        $this->assertTrue(LazyObjectSupport::isUninitializedLazyObject($ghost));

        $returned = $this->invokeInitializeLazyObject($ctx, $ghost);

        $this->assertSame($ghost, $returned);
        $this->assertFalse(LazyObjectSupport::isUninitializedLazyObject($ghost));
    }

    private function registerSvcClass(Context $ctx): ClassEntry
    {
        $class = new ClassEntry('Svc');
        $class->properties[] = new ClassProperty('id', null, new VMVariable());
        $ctx->classes['svc'] = $class;

        return $class;
    }

    private function invokeInitializeLazyObject(Context $ctx, ObjectEntry $object): ObjectEntry
    {
        $rcObj = ReflectionSupport::newReflectionClassObjectForName($ctx, $object->class->name);
        $receiver = new VMVariable(VMVariable::TYPE_OBJECT);
        $receiver->object($rcObj);
        $arg = new VMVariable(VMVariable::TYPE_OBJECT);
        $arg->object($object);

        $method = new ReflectionClassInitializeLazyObject();
        $frame = $method->getFrame($ctx);
        $frame->vmContext = $ctx;
        $frame->calledArgs = [$receiver, $arg];
        $frame->returnVar = new VMVariable();

        $method->execute($frame);

        return $frame->returnVar->resolveIndirect()->toObject();
    }
}
