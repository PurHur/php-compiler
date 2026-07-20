<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionConstant::getDeprecatedVersion() — global + class (#21255). */
final class ReflectionConstantGetDeprecatedVersion extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getDeprecatedVersion');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionConstant($frame, $frame->calledArgs[0]);
        if (ReflectionSupport::isGlobalReflectionConstant($receiver)) {
            ReflectionDeprecatedReturn::globalConstantVersion($frame, $frame->calledArgs[0]);

            return;
        }
        ReflectionDeprecatedReturn::classConstantVersion($frame, $frame->calledArgs[0]);
    }
}
