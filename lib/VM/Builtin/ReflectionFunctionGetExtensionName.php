<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionFunction::getExtensionName() — VM (#6678). */
final class ReflectionFunctionGetExtensionName extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getExtensionName');
    }

    public function execute(Frame $frame): void
    {
        $ctx = VmReflection::requireContext($frame);
        $receiver = ReflectionSupport::requireReflectionFunction($frame, $frame->calledArgs[0]);
        if (null === $frame->returnVar) {
            return;
        }
        if (!ReflectionSupport::isReflectionInternalFunction($receiver)) {
            $frame->returnVar->bool(false);

            return;
        }
        $name = VmReflection::extensionNameForFunction(
            $ctx,
            ReflectionSupport::functionNameFromReflection($receiver)
        );
        $frame->returnVar->string($name);
    }
}
