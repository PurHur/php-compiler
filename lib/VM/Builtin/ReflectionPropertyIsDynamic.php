<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionProperty::isDynamic() — VM (#7295, ext/reflection/php_reflection.c). */
final class ReflectionPropertyIsDynamic extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isDynamic');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionProperty($frame, $frame->calledArgs[0]);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(ReflectionSupport::isDynamicReflectionProperty($receiver));
        }
    }
}
