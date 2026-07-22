<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionMethod::inNamespace() — VM (#22167, ext/reflection/php_reflection.c). */
final class ReflectionMethodInNamespace extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('inNamespace');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionMethod($frame, $frame->calledArgs[0]);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(ReflectionSupport::methodInNamespaceFromReflection($receiver));
        }
    }
}
