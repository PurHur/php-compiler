<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;

/** ReflectionMethod::getDeprecatedMessage() — VM (#6917, ext/reflection/php_reflection.c). */
final class ReflectionMethodGetDeprecatedMessage extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getDeprecatedMessage');
    }

    public function execute(Frame $frame): void
    {
        ReflectionDeprecatedReturn::methodMessage($frame, $frame->calledArgs[0]);
    }
}
