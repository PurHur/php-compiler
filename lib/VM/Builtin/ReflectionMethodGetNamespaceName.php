<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionMethod::getNamespaceName() — VM (#22167, ext/reflection/php_reflection.c). */
final class ReflectionMethodGetNamespaceName extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getNamespaceName');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionMethod($frame, $frame->calledArgs[0]);
        if (null !== $frame->returnVar) {
            $frame->returnVar->string(ReflectionSupport::methodNamespaceNameFromReflection($receiver));
        }
    }
}
