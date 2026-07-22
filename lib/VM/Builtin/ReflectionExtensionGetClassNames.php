<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionExtension::getClassNames() — VM (#22247, ext/reflection/php_reflection.c). */
final class ReflectionExtensionGetClassNames extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getClassNames');
    }

    public function execute(Frame $frame): void
    {
        $ctx = VmReflection::requireContext($frame);
        $receiver = ReflectionSupport::requireReflectionExtension($frame, $frame->calledArgs[0]);
        $nameVar = $receiver->getProperty(ReflectionSupport::PROP_EXTENSION_NAME)->resolveIndirect();
        if (null !== $frame->returnVar) {
            $frame->returnVar->array(
                VmReflection::reflectionExtensionClassNamesTable($ctx, $nameVar->toString())
            );
        }
    }
}
