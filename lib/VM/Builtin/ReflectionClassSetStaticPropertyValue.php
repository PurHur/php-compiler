<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionClass::setStaticPropertyValue() — VM (#6948, ext/reflection/php_reflection.c). */
final class ReflectionClassSetStaticPropertyValue extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('setStaticPropertyValue');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 3) {
            throw new \LogicException('ReflectionClass::setStaticPropertyValue() expects name and value');
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
            'ReflectionClass::setStaticPropertyValue() name',
            1
        );
        VmReflection::setStaticPropertyValueForReflection(
            $entry,
            $ctx,
            $name,
            $frame->calledArgs[2]->resolveIndirect()
        );
    }
}
