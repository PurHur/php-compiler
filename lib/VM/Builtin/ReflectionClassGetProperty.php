<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;

/** ReflectionClass::getProperty() — VM (#4395). */
final class ReflectionClassGetProperty extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getProperty');
    }

    public function execute(Frame $frame): void
    {
        $userArgCount = \count($frame->calledArgs) - 1;
        if (1 !== $userArgCount) {
            throw new \ArgumentCountError(\sprintf(
                'ReflectionClass::getProperty() expects exactly 1 argument, %d given',
                $userArgCount
            ));
        }
        $receiver = ReflectionSupport::requireReflectionClass($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        $className = ReflectionSupport::classNameFromReflection($receiver);
        $entry = VmReflection::resolveClassEntry($ctx, $className);
        if (null === $entry) {
            throw new \LogicException('ReflectionClass refers to unknown class in this compiler build');
        }
        $property = VmReflection::stringArg($frame->calledArgs[1], 'ReflectionClass::getProperty() name', 1);
        $meta = VmReflection::findClassProperty($entry, $property, $ctx);
        if (null === $meta) {
            ReflectionSupport::throwReflectionException(
                ReflectionSupport::propertyNotFoundMessage($className, $property)
            );
        }
        if (null !== $frame->returnVar) {
            $out = new Variable(Variable::TYPE_OBJECT);
            $out->object(VmReflection::reflectionPropertyObject($ctx, $className, $meta));
            $frame->returnVar->copyFrom($out);
        }
    }
}
