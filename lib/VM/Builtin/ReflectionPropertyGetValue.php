<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;

/** ReflectionProperty::getValue($object) — VM (#3354). */
final class ReflectionPropertyGetValue extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getValue');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('ReflectionProperty::getValue() expects an object');
        }
        // php-src: zim_ReflectionProperty_getValue — at most 1 user arg (#30896)
        $this->requireUserArgCountRange($frame, 'ReflectionProperty::getValue', 0, 1);
        $receiver = ReflectionSupport::requireReflectionProperty($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        ReflectionSupport::assertReflectionPropertyAccessible($ctx, $receiver);
        $className = ReflectionSupport::classNameFromReflection($receiver);
        $entry = VmReflection::resolveClassEntry($ctx, $className);
        if (null === $entry) {
            throw new \LogicException('ReflectionProperty refers to unknown class in this compiler build');
        }
        $property = ReflectionSupport::propertyNameFromReflection($receiver);
        $staticKey = VmReflection::findStaticPropertyKey($entry, $property, $ctx);
        if (null !== $staticKey) {
            if (null === $frame->returnVar) {
                return;
            }
            $classLc = strtolower(ltrim($entry->name, '\\'));
            $frame->returnVar->copyFrom(
                $ctx->runtime->vm()->readStaticPropertyForReflection(
                    $classLc,
                    $property,
                    $entry->staticProperties[$staticKey],
                    null,
                    VmReflection::reflectionCallerFrame($frame)
                )
            );

            return;
        }
        if (\count($frame->calledArgs) < 2) {
            throw new \TypeError(
                'ReflectionProperty::getValue(): Argument #1 ($object) must be provided for instance properties'
            );
        }
        if (VmReflection::isEnumReflectionPseudoProperty($entry, $property)) {
            $object = $frame->calledArgs[1]->resolveIndirect();
            if (!EnumCaseSupport::isEnumCaseVariable($object)) {
                if (Variable::TYPE_OBJECT !== $object->type && Variable::TYPE_ENUM_CASE !== $object->type) {
                    throw new \LogicException('ReflectionProperty::getValue() expects an object');
                }
                ReflectionSupport::throwReflectionException(
                    'Given object is not an instance of the class this property was declared in'
                );
            }
            if (!VmReflection::isInstanceOfObject($ctx, $object, $className)) {
                ReflectionSupport::throwReflectionException(
                    'Given object is not an instance of the class this property was declared in'
                );
            }
            if (null === $frame->returnVar) {
                return;
            }
            $propLc = strtolower($property);
            $vars = EnumCaseSupport::objectVarsForCaseVariable($object);
            if (isset($vars[$propLc])) {
                $frame->returnVar->copyFrom($vars[$propLc]);

                return;
            }
            if (Variable::TYPE_OBJECT === $object->type) {
                $frame->returnVar->copyFrom(
                    EnumCaseSupport::getProperty($object->toObject(), $property, $ctx, $frame)
                );

                return;
            }
            $frame->returnVar->null();

            return;
        }
        if (ReflectionSupport::isDynamicReflectionProperty($receiver)) {
            if (\count($frame->calledArgs) < 2) {
                throw new \TypeError(
                    'ReflectionProperty::getValue(): Argument #1 ($object) must be provided for instance properties'
                );
            }
            $object = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_OBJECT !== $object->type) {
                throw new \TypeError(
                    'ReflectionProperty::getValue(): Argument #1 ($object) must be of type ?object, '
                    .EnumCaseSupport::typeNameForVariable($object).' given'
                );
            }
            if (null === $frame->returnVar) {
                return;
            }
            $raw = $ctx->runtime->vm()->readInstancePropertyRawForReflection(
                $object->toObject(),
                $property,
                null
            );
            $frame->returnVar->copyFrom($raw);

            return;
        }
        $instanceName = VmReflection::findInstancePropertyName($entry, $property, $ctx);
        if (null === $instanceName) {
            ReflectionSupport::throwReflectionException(
                ReflectionSupport::propertyNotFoundMessage($className, $property)
            );
        }
        $object = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $object->type) {
            throw new \TypeError(
                'ReflectionProperty::getValue(): Argument #1 ($object) must be of type ?object, '
                .EnumCaseSupport::typeNameForVariable($object).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $meta = VmReflection::findClassProperty($entry, $property, $ctx);
        if (null !== $meta?->getHookMethodLc) {
            $scopeFrame = $frame;
            while (null !== $scopeFrame && null !== $scopeFrame->handler) {
                $scopeFrame = $scopeFrame->parent;
            }
            if (null === $scopeFrame) {
                $scopeFrame = $frame;
            }
            $hookValue = $ctx->runtime->vm()->readObjectForeachProperty(
                $object->toObject(),
                $instanceName,
                $scopeFrame,
                false
            );
            $frame->returnVar->copyFrom($hookValue->resolveIndirect());

            return;
        }
        $frame->returnVar->copyFrom($object->toObject()->getProperty($instanceName)->resolveIndirect());
    }
}
