<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionConstant::getNamespaceName() — VM (#21551, ext/reflection/php_reflection.c). */
final class ReflectionConstantGetNamespaceName extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getNamespaceName');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionConstant($frame, $frame->calledArgs[0]);
        if (null !== $frame->returnVar) {
            $frame->returnVar->string(
                ReflectionSupport::globalConstantNamespaceName(
                    ReflectionSupport::constantNameFromReflection($receiver)
                )
            );
        }
    }
}
