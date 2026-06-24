<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;

/** ReflectionProperty::setRawValue($object, $value) — VM (#6451, ext/reflection/php_reflection.c). */
final class ReflectionPropertySetRawValue extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('setRawValue');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 3) {
            throw new \ArgumentCountError(sprintf(
                'ReflectionProperty::setRawValue() expects exactly 2 arguments, %d given',
                \count($frame->calledArgs) - 1
            ));
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
        if (VmReflection::isEnumReflectionPseudoProperty($entry, $property)) {
            throw new \LogicException('Cannot set property on enum case');
        }
        $instanceName = VmReflection::findInstancePropertyName($entry, $property, $ctx);
        if (null === $instanceName) {
            ReflectionSupport::throwReflectionException(
                ReflectionSupport::propertyNotFoundMessage($className, $property)
            );
        }
        $meta = VmReflection::findClassProperty($entry, $property, $ctx);
        $objectVar = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $objectVar->type) {
            throw new \TypeError(
                'ReflectionProperty::setRawValue(): Argument #1 ($object) must be of type object, '
                .EnumCaseSupport::typeNameForVariable($objectVar).' given'
            );
        }
        if (!VmReflection::isInstanceOfObject($ctx, $objectVar, $className)) {
            throw new \TypeError(sprintf(
                'ReflectionProperty::setRawValue(): Argument #1 ($object) must be an instance of %s, %s given',
                $className,
                $objectVar->toObject()->class->name
            ));
        }
        $strict = null !== $frame->parent
            ? $frame->parent->block->strictTypes
            : $frame->block->strictTypes;
        $ctx->runtime->vm()->writeInstancePropertyRawForReflection(
            $objectVar->toObject(),
            $instanceName,
            $meta,
            $frame->calledArgs[2],
            $strict
        );
    }
}
