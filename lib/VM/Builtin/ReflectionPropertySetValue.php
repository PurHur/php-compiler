<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;

/** ReflectionProperty::setValue($object, $value) — VM (#4469, ext/reflection/php_reflection.c). */
final class ReflectionPropertySetValue extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('setValue');
    }

    public function execute(Frame $frame): void
    {
        $userArgCount = \count($frame->calledArgs) - 1;
        if ($userArgCount < 1) {
            throw new \ArgumentCountError(sprintf(
                'ReflectionProperty::setValue() expects exactly 2 arguments, %d given',
                $userArgCount
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
            $valueIndex = \count($frame->calledArgs) >= 3 ? 2 : 1;
            $ctx->runtime->vm()->writeStaticPropertyForReflection(
                $entry,
                $property,
                $frame->calledArgs[$valueIndex]->resolveIndirect(),
                VmReflection::reflectionCallerFrame($frame)
            );

            return;
        }
        if (\count($frame->calledArgs) < 3) {
            throw new \ArgumentCountError(sprintf(
                'ReflectionProperty::setValue() expects exactly 2 arguments, %d given',
                $userArgCount
            ));
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
                'ReflectionProperty::setValue(): Argument #1 ($object) must be of type ?object, '
                .EnumCaseSupport::typeNameForVariable($objectVar).' given'
            );
        }
        if (!VmReflection::isInstanceOfObject($ctx, $objectVar, $className)) {
            ReflectionSupport::throwReflectionException(
                'Given object is not an instance of the class this property was declared in'
            );
        }
        $ctx->runtime->vm()->writeInstancePropertyForReflection(
            $objectVar->toObject(),
            $instanceName,
            $meta,
            $frame->calledArgs[2]->resolveIndirect(),
            VmReflection::reflectionCallerFrame($frame)
        );
    }
}
