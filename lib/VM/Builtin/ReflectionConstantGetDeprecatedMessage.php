<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionConstant::getDeprecatedMessage() — global + class (#21255). */
final class ReflectionConstantGetDeprecatedMessage extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getDeprecatedMessage');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionConstant($frame, $frame->calledArgs[0]);
        if (ReflectionSupport::isGlobalReflectionConstant($receiver)) {
            ReflectionDeprecatedReturn::globalConstantMessage($frame, $frame->calledArgs[0]);

            return;
        }
        ReflectionDeprecatedReturn::classConstantMessage($frame, $frame->calledArgs[0]);
    }
}
