<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionClass::getReflectionConstants() — VM (#6662, php-src ext/reflection/php_reflection.c). */
final class ReflectionClassGetReflectionConstants extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getReflectionConstants');
    }

    public function execute(Frame $frame): void
    {
        $this->requireUserArgCountRange($frame, 'ReflectionClass::getReflectionConstants', 0, 1);
        $receiver = ReflectionSupport::requireReflectionClass($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        $className = ReflectionSupport::classNameFromReflection($receiver);
        $entry = VmReflection::resolveClassEntry($ctx, $className);
        if (null === $entry) {
            throw new \LogicException('ReflectionClass refers to unknown class in this compiler build');
        }
        $filter = VmReflection::reflectionConstantsFilterArg($frame, 1, 'ReflectionClass::getReflectionConstants');
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom(
                VmReflection::reflectionClassReflectionConstantsMap($ctx, $entry, $filter)
            );
        }
    }
}
