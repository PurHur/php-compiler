<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
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
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('ReflectionProperty::getValue() expects an object');
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
            if (null === $frame->returnVar) {
                return;
            }
            $frame->returnVar->copyFrom($entry->staticProperties[$staticKey]);

            return;
        }
        $instanceName = VmReflection::findInstancePropertyName($entry, $property, $ctx);
        if (null === $instanceName) {
            throw new \LogicException("Property {$property} does not exist on {$className}");
        }
        $object = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $object->type) {
            throw new \LogicException('ReflectionProperty::getValue() expects an object');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->copyFrom($object->toObject()->getProperty($instanceName)->resolveIndirect());
    }
}
