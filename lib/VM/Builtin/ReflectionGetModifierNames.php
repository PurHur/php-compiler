<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;

/**
 * Reflection::getModifierNames() — VM (#22127, ext/reflection/php_reflection.c).
 *
 * php-src: zim_Reflection_getModifierNames
 */
final class ReflectionGetModifierNames extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getModifierNames');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \ArgumentCountError(
                'Reflection::getModifierNames() expects exactly 1 argument, 0 given'
            );
        }
        $modifiers = VmMath::parseIntBuiltinArg(
            $frame->calledArgs[0],
            'Reflection::getModifierNames',
            1,
            'modifiers'
        );
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom(VmReflection::reflectionGetModifierNames($modifiers));
        }
    }
}
