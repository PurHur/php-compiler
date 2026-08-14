<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\TypedPropertyCheck;
use PHPCompiler\VM\Variable;

/** ReflectionProperty::isInitialized(?object) — VM (#6653, ext/reflection/php_reflection.c). */
final class ReflectionPropertyIsInitialized extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isInitialized');
    }

    public function execute(Frame $frame): void
    {
        // php-src: zim_ReflectionProperty_isInitialized — at most 1 user arg (#30896)
        $this->requireUserArgCountRange($frame, 'ReflectionProperty::isInitialized', 0, 1);
        $receiver = ReflectionSupport::requireReflectionProperty($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        $className = ReflectionSupport::classNameFromReflection($receiver);
        $entry = VmReflection::resolveClassEntry($ctx, $className);
        if (null === $entry) {
            throw new \LogicException('ReflectionProperty refers to unknown class in this compiler build');
        }
        $property = ReflectionSupport::propertyNameFromReflection($receiver);
        $staticKey = VmReflection::findStaticPropertyKey($entry, $property, $ctx);
        $instanceName = VmReflection::findInstancePropertyName($entry, $property, $ctx);
        if (null === $staticKey && null === $instanceName) {
            ReflectionSupport::throwReflectionException(
                ReflectionSupport::propertyNotFoundMessage($className, $property)
            );
        }
        if (null !== $staticKey) {
            if (null === $frame->returnVar) {
                return;
            }
            $frame->returnVar->bool(self::isStaticPropertyInitialized($entry, $staticKey));

            return;
        }
        $meta = VmReflection::findClassProperty($entry, $property, $ctx);
        if (null === $meta) {
            ReflectionSupport::throwReflectionException(
                ReflectionSupport::propertyNotFoundMessage($className, $property)
            );
        }
        $objectArg = \count($frame->calledArgs) >= 2
            ? $frame->calledArgs[1]->resolveIndirect()
            : null;
        if (null === $objectArg || Variable::TYPE_NULL === $objectArg->type) {
            throw new \TypeError(
                'ReflectionProperty::isInitialized(): Argument #1 ($object) must be provided for instance properties'
            );
        }
        if (Variable::TYPE_OBJECT !== $objectArg->type) {
            throw new \TypeError(
                'ReflectionProperty::isInitialized(): Argument #1 ($object) must be of type ?object, '
                .EnumCaseSupport::typeNameForVariable($objectArg).' given'
            );
        }
        if (!VmReflection::isInstanceOfObject($ctx, $objectArg, $className)) {
            ReflectionSupport::throwReflectionException(
                'Given object is not an instance of the class this property was declared in'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(
            self::isInstancePropertyInitialized($objectArg->toObject(), $meta)
        );
    }

    private static function isStaticPropertyInitialized(
        \PHPCompiler\VM\ClassEntry $entry,
        string $staticKey
    ): bool {
        if (!isset($entry->staticProperties[$staticKey])) {
            return false;
        }
        $slot = $entry->staticProperties[$staticKey]->resolveIndirect();
        if (!$slot->hasDeclaredTypeConstraint()) {
            return true;
        }

        return !TypedPropertyCheck::isUninitialized($slot);
    }

    private static function isInstancePropertyInitialized(
        \PHPCompiler\VM\ObjectEntry $object,
        \PHPCompiler\VM\ClassProperty $meta
    ): bool {
        if ($meta->propertyHookVirtual || !self::isTypedProperty($meta)) {
            return true;
        }
        if (!$object->hasProperty($meta->name)) {
            return false;
        }
        $slot = $object->getProperty($meta->name)->resolveIndirect();

        return !($slot->isUndefined() || TypedPropertyCheck::isUninitialized($slot));
    }

    private static function isTypedProperty(\PHPCompiler\VM\ClassProperty $meta): bool
    {
        return $meta->prototype->hasDeclaredTypeConstraint();
    }
}
