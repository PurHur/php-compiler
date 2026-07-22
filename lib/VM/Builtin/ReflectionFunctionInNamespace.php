<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionFunction::inNamespace() — VM (#22144, ext/reflection/php_reflection.c). */
final class ReflectionFunctionInNamespace extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('inNamespace');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionFunction($frame, $frame->calledArgs[0]);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(ReflectionSupport::functionInNamespaceFromReflection($receiver));
        }
    }
}
