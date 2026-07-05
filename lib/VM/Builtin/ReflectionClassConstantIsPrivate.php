<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCfg\Func as CfgFunc;

/** ReflectionClassConstant::isPrivate() — VM (#4386, ext/reflection/php_reflection.c). */
final class ReflectionClassConstantIsPrivate extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isPrivate');
    }

    public function execute(Frame $frame): void
    {
        if (null !== $frame->returnVar) {
            $flags = ReflectionClassConstantVisibility::constantVisibilityFlags($frame);
            $frame->returnVar->bool(($flags & CfgFunc::FLAG_PRIVATE) !== 0);
        }
    }
}
