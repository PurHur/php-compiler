<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionClass::getNamespaceName() — VM (#22087, ext/reflection/php_reflection.c). */
final class ReflectionClassGetNamespaceName extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getNamespaceName');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionClass($frame, $frame->calledArgs[0]);
        if (null !== $frame->returnVar) {
            $frame->returnVar->string(ReflectionSupport::classNamespaceNameFromReflection($receiver));
        }
    }
}
