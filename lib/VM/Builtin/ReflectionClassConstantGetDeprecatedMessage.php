<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;

/** ReflectionClassConstant::getDeprecatedMessage() — VM (#6917, ext/reflection/php_reflection.c). */
final class ReflectionClassConstantGetDeprecatedMessage extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getDeprecatedMessage');
    }

    public function execute(Frame $frame): void
    {
        ReflectionDeprecatedReturn::classConstantMessage($frame, $frame->calledArgs[0]);
    }
}
