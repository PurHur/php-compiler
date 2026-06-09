<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;

/** ReflectionMethod::getDeprecatedVersion() — VM (#6917, ext/reflection/php_reflection.c). */
final class ReflectionMethodGetDeprecatedVersion extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getDeprecatedVersion');
    }

    public function execute(Frame $frame): void
    {
        ReflectionDeprecatedReturn::methodVersion($frame, $frame->calledArgs[0]);
    }
}
