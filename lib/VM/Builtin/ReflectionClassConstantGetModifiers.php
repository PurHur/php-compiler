<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionClassConstant::getModifiers() — VM (#17360, ext/reflection/php_reflection.c). */
final class ReflectionClassConstantGetModifiers extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getModifiers');
    }

    public function execute(Frame $frame): void
    {
        $ctx = VmReflection::requireContext($frame);
        $receiver = ReflectionSupport::requireReflectionClassConstant($frame, $frame->calledArgs[0]);
        $className = ReflectionSupport::classNameFromReflection($receiver);
        $entry = VmReflection::resolveClassEntry($ctx, $className);
        if (null === $entry) {
            throw new \LogicException('ReflectionClassConstant refers to unknown class in this compiler build');
        }
        $constant = ReflectionSupport::constantNameFromReflection($receiver);
        $key = VmReflection::findClassConstantKey($entry, $constant, $ctx);
        if (null === $key) {
            ReflectionSupport::throwReflectionException(
                ReflectionSupport::constantNotFoundMessage($className, $constant)
            );
        }
        if (null !== $frame->returnVar) {
            $visibility = ReflectionClassConstantVisibility::constantVisibilityFlags($frame);
            $frame->returnVar->int(
                VmReflection::cfgClassConstantFlagsToReflectionModifiers(
                    $visibility,
                    isset($entry->constFinal[$key])
                )
            );
        }
    }
}
