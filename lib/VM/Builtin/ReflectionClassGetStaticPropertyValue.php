<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionClass::getStaticPropertyValue() — VM (#6948, ext/reflection/php_reflection.c). */
final class ReflectionClassGetStaticPropertyValue extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getStaticPropertyValue');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('ReflectionClass::getStaticPropertyValue() expects a property name');
        }
        $receiver = ReflectionSupport::requireReflectionClass($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        $className = ReflectionSupport::classNameFromReflection($receiver);
        $entry = VmReflection::resolveClassEntry($ctx, $className);
        if (null === $entry) {
            throw new \LogicException('ReflectionClass refers to unknown class in this compiler build');
        }
        $name = VmReflection::stringArg(
            $frame->calledArgs[1],
            'ReflectionClass::getStaticPropertyValue() name',
            1
        );
        $default = \count($frame->calledArgs) >= 3
            ? $frame->calledArgs[2]->resolveIndirect()
            : null;
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->copyFrom(
            VmReflection::getStaticPropertyValueForReflection($entry, $ctx, $name, $default, $frame)
        );
    }
}
