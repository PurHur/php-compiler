<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;

/** ReflectionClassConstant::getDeprecatedVersion() — VM (#6917, ext/reflection/php_reflection.c). */
final class ReflectionClassConstantGetDeprecatedVersion extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getDeprecatedVersion');
    }

    public function execute(Frame $frame): void
    {
        ReflectionDeprecatedReturn::classConstantVersion($frame, $frame->calledArgs[0]);
    }
}
