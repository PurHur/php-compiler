<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionClass::getConstants() — VM (#6950, php-src ext/reflection/php_reflection.c). */
final class ReflectionClassGetConstants extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getConstants');
    }

    public function execute(Frame $frame): void
    {
        $this->requireUserArgCountRange($frame, 'ReflectionClass::getConstants', 0, 1);
        $receiver = ReflectionSupport::requireReflectionClass($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        $className = ReflectionSupport::classNameFromReflection($receiver);
        $entry = VmReflection::resolveClassEntry($ctx, $className);
        if (null === $entry) {
            throw new \LogicException('ReflectionClass refers to unknown class in this compiler build');
        }
        $filter = VmReflection::reflectionConstantsFilterArg($frame, 1, 'ReflectionClass::getConstants');
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom(
                VmReflection::reflectionClassConstantsMap($ctx, $entry, $filter)
            );
        }
    }
}
