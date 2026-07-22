<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionClass::inNamespace() — VM (#22087, ext/reflection/php_reflection.c). */
final class ReflectionClassInNamespace extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('inNamespace');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionClass($frame, $frame->calledArgs[0]);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(ReflectionSupport::classInNamespaceFromReflection($receiver));
        }
    }
}
