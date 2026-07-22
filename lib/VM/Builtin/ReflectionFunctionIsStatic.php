<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionFunction::isStatic() — VM (#22024, ext/reflection/php_reflection.c). */
final class ReflectionFunctionIsStatic extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isStatic');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionFunction($frame, $frame->calledArgs[0]);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(ReflectionSupport::isReflectionFunctionStatic($receiver));
        }
    }
}
