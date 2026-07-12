<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionExtension::getClasses() — VM (#18326, ext/reflection/php_reflection.c). */
final class ReflectionExtensionGetClasses extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getClasses');
    }

    public function execute(Frame $frame): void
    {
        $ctx = VmReflection::requireContext($frame);
        $receiver = ReflectionSupport::requireReflectionExtension($frame, $frame->calledArgs[0]);
        $nameVar = $receiver->getProperty(ReflectionSupport::PROP_EXTENSION_NAME)->resolveIndirect();
        if (null !== $frame->returnVar) {
            $frame->returnVar->array(
                VmReflection::reflectionExtensionClassesTable($ctx, $nameVar->toString())
            );
        }
    }
}
