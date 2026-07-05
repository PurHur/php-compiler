<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\MethodVisibility;

/** ReflectionClassConstant::isPublic() — VM (#4386, ext/reflection/php_reflection.c). */
final class ReflectionClassConstantIsPublic extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isPublic');
    }

    public function execute(Frame $frame): void
    {
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(
                MethodVisibility::isPublic(ReflectionClassConstantVisibility::constantVisibilityFlags($frame))
            );
        }
    }
}
