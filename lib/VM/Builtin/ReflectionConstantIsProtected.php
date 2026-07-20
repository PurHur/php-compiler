<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;
use PHPCfg\Func as CfgFunc;

/** ReflectionConstant::isProtected() — globals are never protected (#21255). */
final class ReflectionConstantIsProtected extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isProtected');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionConstant($frame, $frame->calledArgs[0]);
        if (null !== $frame->returnVar) {
            if (ReflectionSupport::isGlobalReflectionConstant($receiver)) {
                $frame->returnVar->bool(false);

                return;
            }
            $flags = ReflectionClassConstantVisibility::constantVisibilityFlags($frame);
            $frame->returnVar->bool(($flags & CfgFunc::FLAG_PROTECTED) !== 0);
        }
    }
}
