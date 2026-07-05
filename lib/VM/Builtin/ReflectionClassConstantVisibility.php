<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\MethodVisibility;
use PHPCfg\Func as CfgFunc;
use PHPCompiler\VM\ReflectionSupport;

/**
 * Shared visibility resolution for ReflectionClassConstant::{isPublic,isProtected,isPrivate}
 * (#4386, ext/reflection/php_reflection.c).
 */
final class ReflectionClassConstantVisibility
{
    public static function constantVisibilityFlags(Frame $frame): int
    {
        $receiver = ReflectionSupport::requireReflectionClassConstant($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        $className = ReflectionSupport::classNameFromReflection($receiver);
        $entry = VmReflection::resolveClassEntry($ctx, $className);
        if (null === $entry) {
            throw new \LogicException('ReflectionClassConstant refers to unknown class in this compiler build');
        }
        $constant = ReflectionSupport::constantNameFromReflection($receiver);
        $decl = VmReflection::findClassConstantDecl($entry, $constant, $ctx);
        if (null === $decl) {
            ReflectionSupport::throwReflectionException(
                ReflectionSupport::constantNotFoundMessage($className, $constant)
            );
        }

        return MethodVisibility::mask(
            $decl['declaring']->constVisibility[$decl['constLc']] ?? CfgFunc::FLAG_PUBLIC
        );
    }
}
