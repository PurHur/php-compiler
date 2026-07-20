<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionConstant::__toString() — VM (#21551, ext/reflection/php_reflection.c _const_string). */
final class ReflectionConstantToString extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__toString');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionConstant($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        if (null !== $frame->returnVar) {
            $frame->returnVar->string(ReflectionSupport::globalReflectionConstantToString($ctx, $receiver));
        }
    }
}
