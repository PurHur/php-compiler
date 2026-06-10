<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\LazyObjectSupport;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;

/** ReflectionProperty::setRawValueWithoutLazyInitialization() — VM (#7095). */
final class ReflectionPropertySetRawValueWithoutLazyInitialization extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('setRawValueWithoutLazyInitialization');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 3) {
            throw new \LogicException(
                'ReflectionProperty::setRawValueWithoutLazyInitialization() expects object and value'
            );
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
            throw new \Error('Cannot set raw value on static property');
        }
        $instanceName = VmReflection::findInstancePropertyName($entry, $property, $ctx);
        if (null === $instanceName) {
            ReflectionSupport::throwReflectionException(
                ReflectionSupport::propertyNotFoundMessage($className, $property)
            );
        }
        $meta = VmReflection::findClassProperty($entry, $property, $ctx);
        if (null === $meta) {
            ReflectionSupport::throwReflectionException(
                ReflectionSupport::propertyNotFoundMessage($className, $property)
            );
        }
        $objectVar = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $objectVar->type) {
            throw new \TypeError(
                'ReflectionProperty::setRawValueWithoutLazyInitialization(): Argument #1 ($object) must be of type object, '
                .EnumCaseSupport::typeNameForVariable($objectVar).' given'
            );
        }
        if (!VmReflection::isInstanceOfObject($ctx, $objectVar, $className)) {
            throw new \TypeError(sprintf(
                'ReflectionProperty::setRawValueWithoutLazyInitialization(): Argument #1 ($object) must be an instance of %s, %s given',
                $className,
                $objectVar->toObject()->class->name
            ));
        }
        $strict = null !== $frame->parent
            ? $frame->parent->block->strictTypes
            : $frame->block->strictTypes;
        LazyObjectSupport::setRawValueWithoutLazyInitialization(
            $objectVar->toObject(),
            $meta,
            $frame->calledArgs[2],
            $strict
        );
    }
}
