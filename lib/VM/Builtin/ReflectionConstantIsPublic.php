<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\MethodVisibility;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionConstant::isPublic() — globals are public (#21255). */
final class ReflectionConstantIsPublic extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isPublic');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionConstant($frame, $frame->calledArgs[0]);
        if (null !== $frame->returnVar) {
            if (ReflectionSupport::isGlobalReflectionConstant($receiver)) {
                $frame->returnVar->bool(true);

                return;
            }
            $frame->returnVar->bool(
                MethodVisibility::isPublic(ReflectionClassConstantVisibility::constantVisibilityFlags($frame))
            );
        }
    }
}
