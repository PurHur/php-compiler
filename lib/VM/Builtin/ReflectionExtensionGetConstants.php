<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionExtension::getConstants() — VM (#18326, ext/reflection/php_reflection.c). */
final class ReflectionExtensionGetConstants extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getConstants');
    }

    public function execute(Frame $frame): void
    {
        $ctx = VmReflection::requireContext($frame);
        $receiver = ReflectionSupport::requireReflectionExtension($frame, $frame->calledArgs[0]);
        $nameVar = $receiver->getProperty(ReflectionSupport::PROP_EXTENSION_NAME)->resolveIndirect();
        if (null !== $frame->returnVar) {
            $frame->returnVar->array(
                VmReflection::reflectionExtensionConstantsTable($ctx, $nameVar->toString())
            );
        }
    }
}
