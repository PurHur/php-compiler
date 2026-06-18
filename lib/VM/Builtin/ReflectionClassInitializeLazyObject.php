<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\LazyObjectSupport;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;

/** ReflectionClass::initializeLazyObject() — VM (#7054, ext/reflection/php_reflection.c). */
final class ReflectionClassInitializeLazyObject extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('initializeLazyObject');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('ReflectionClass::initializeLazyObject() expects an object');
        }
        $receiver = ReflectionSupport::requireReflectionClass($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        $className = ReflectionSupport::classNameFromReflection($receiver);
        $objectVar = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $objectVar->type) {
            throw new \TypeError(
                'ReflectionClass::initializeLazyObject(): Argument #1 ($object) must be of type object'
            );
        }
        if (!VmReflection::isInstanceOfObject($ctx, $objectVar, $className)) {
            throw new \TypeError(sprintf(
                'ReflectionClass::initializeLazyObject(): Argument #1 ($object) must be an instance of %s, %s given',
                $className,
                $objectVar->toObject()->class->name
            ));
        }
        $object = $objectVar->toObject();
        if (LazyObjectSupport::isUninitializedLazyObject($object)) {
            $initializer = LazyObjectSupport::getInitializer($object);
            if (null === $initializer) {
                LazyObjectSupport::markAsInitialized($object);
            } else {
                $vm = $ctx->runtime->vm;
                if (null === $vm) {
                    throw new \LogicException('ReflectionClass::initializeLazyObject() requires VM');
                }
                LazyObjectSupport::ensureInitialized($vm, $object);
            }
        }
        if (LazyObjectSupport::isUninitializedLazyObject($object)) {
            throw new \LogicException('ReflectionClass::initializeLazyObject() failed to initialize lazy object');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $out = new Variable(Variable::TYPE_OBJECT);
        $out->object(LazyObjectSupport::getLazyInstance($object));
        $frame->returnVar->copyFrom($out);
    }
}
