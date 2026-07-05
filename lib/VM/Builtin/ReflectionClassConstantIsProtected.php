<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCfg\Func as CfgFunc;

/** ReflectionClassConstant::isProtected() — VM (#4386, ext/reflection/php_reflection.c). */
final class ReflectionClassConstantIsProtected extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isProtected');
    }

    public function execute(Frame $frame): void
    {
        if (null !== $frame->returnVar) {
            $flags = ReflectionClassConstantVisibility::constantVisibilityFlags($frame);
            $frame->returnVar->bool(($flags & CfgFunc::FLAG_PROTECTED) !== 0);
        }
    }
}
