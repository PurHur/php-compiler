<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionClass::hasConstant() — VM (#6301, ext/reflection/php_reflection.c). */
final class ReflectionClassHasConstant extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('hasConstant');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('ReflectionClass::hasConstant() expects a constant name');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $receiver = ReflectionSupport::requireReflectionClass($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        $className = ReflectionSupport::classNameFromReflection($receiver);
        $entry = VmReflection::resolveClassEntry($ctx, $className);
        if (null === $entry) {
            throw new \LogicException('ReflectionClass refers to unknown class in this compiler build');
        }
        $constant = VmReflection::stringArg($frame->calledArgs[1], 'ReflectionClass::hasConstant() name', 1);
        $frame->returnVar->bool(
            VmReflection::classHasConstantForReflection($entry, $ctx, $constant)
        );
    }
}
