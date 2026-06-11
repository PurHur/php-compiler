<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\LazyObjectSupport;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;

/** ReflectionProperty::skipLazyInitialization(object) — VM (#7094, ext/reflection/php_reflection.c). */
final class ReflectionPropertySkipLazyInitialization extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('skipLazyInitialization');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('ReflectionProperty::skipLazyInitialization() expects an object');
        }
        $receiver = ReflectionSupport::requireReflectionProperty($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        $className = ReflectionSupport::classNameFromReflection($receiver);
        $entry = VmReflection::resolveClassEntry($ctx, $className);
        if (null === $entry) {
            throw new \LogicException('ReflectionProperty refers to unknown class in this compiler build');
        }
        $property = ReflectionSupport::propertyNameFromReflection($receiver);
        $staticKey = VmReflection::findStaticPropertyKey($entry, $property, $ctx);
        if (null !== $staticKey) {
            ReflectionSupport::throwReflectionException(sprintf(
                'Can not use skipLazyInitialization on static property %s::$%s',
                $className,
                $property
            ));
        }
        $instanceName = VmReflection::findInstancePropertyName($entry, $property, $ctx);
        if (null === $instanceName) {
            ReflectionSupport::throwReflectionException(sprintf(
                'Can not use skipLazyInitialization on dynamic property %s::$%s',
                $className,
                $property
            ));
        }
        $meta = VmReflection::findClassProperty($entry, $property, $ctx);
        if (null === $meta) {
            ReflectionSupport::throwReflectionException(sprintf(
                'Can not use skipLazyInitialization on dynamic property %s::$%s',
                $className,
                $property
            ));
        }
        if ($meta->propertyHookVirtual) {
            ReflectionSupport::throwReflectionException(sprintf(
                'Can not use skipLazyInitialization on virtual property %s::$%s',
                $className,
                $property
            ));
        }
        $objectVar = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $objectVar->type) {
            throw new \TypeError(
                'ReflectionProperty::skipLazyInitialization(): Argument #1 ($object) must be of type object, '
                .EnumCaseSupport::typeNameForVariable($objectVar).' given'
            );
        }
        if (!VmReflection::isInstanceOfObject($ctx, $objectVar, $className)) {
            throw new \TypeError(sprintf(
                'ReflectionProperty::skipLazyInitialization(): Argument #1 ($object) must be an instance of %s, %s given',
                $className,
                $objectVar->toObject()->class->name
            ));
        }
        LazyObjectSupport::skipLazyInitialization($objectVar->toObject(), $meta);
    }
}
