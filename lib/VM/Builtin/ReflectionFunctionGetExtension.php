<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionFunction::getExtension() — VM (#22099, ext/reflection/php_reflection.c). */
final class ReflectionFunctionGetExtension extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getExtension');
    }

    public function execute(Frame $frame): void
    {
        $ctx = VmReflection::requireContext($frame);
        $receiver = ReflectionSupport::requireReflectionFunction($frame, $frame->calledArgs[0]);
        if (null === $frame->returnVar) {
            return;
        }
        if (!ReflectionSupport::isReflectionInternalFunction($receiver)) {
            $frame->returnVar->null();

            return;
        }
        $name = VmReflection::extensionNameForFunction(
            $ctx,
            ReflectionSupport::functionNameFromReflection($receiver)
        );
        $frame->returnVar->object(ReflectionSupport::newReflectionExtensionObject($ctx, $name));
    }
}
