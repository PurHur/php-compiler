<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionPropertyHookSupport;

/** ReflectionProperty::isDynamic() — VM (#7295, ext/reflection/php_reflection.c). */
final class ReflectionPropertyIsDynamic extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isDynamic');
    }

    public function execute(Frame $frame): void
    {
        ReflectionPropertyHookSupport::resolveProperty($frame, $frame->calledArgs[0]);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(ReflectionPropertyHookSupport::isDynamic());
        }
    }
}
