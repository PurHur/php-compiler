<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionFunction::getNamespaceName() — VM (#22144, ext/reflection/php_reflection.c). */
final class ReflectionFunctionGetNamespaceName extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getNamespaceName');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionFunction($frame, $frame->calledArgs[0]);
        if (null !== $frame->returnVar) {
            $frame->returnVar->string(ReflectionSupport::functionNamespaceNameFromReflection($receiver));
        }
    }
}
