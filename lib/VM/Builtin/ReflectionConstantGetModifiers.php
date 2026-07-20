<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;
use PHPCfg\Func as CfgFunc;

/** ReflectionConstant::getModifiers() — globals report IS_PUBLIC (#21255). */
final class ReflectionConstantGetModifiers extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getModifiers');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionConstant($frame, $frame->calledArgs[0]);
        if (ReflectionSupport::isGlobalReflectionConstant($receiver)) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->int(
                    VmReflection::cfgClassConstantFlagsToReflectionModifiers(CfgFunc::FLAG_PUBLIC, false)
                );
            }

            return;
        }
        $ctx = VmReflection::requireContext($frame);
        $className = ReflectionSupport::classNameFromReflection($receiver);
        $entry = VmReflection::resolveClassEntry($ctx, $className);
        if (null === $entry) {
            throw new \LogicException('ReflectionConstant refers to unknown class in this compiler build');
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
