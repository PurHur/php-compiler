<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionClassConstant::getDocComment() — VM (#22419, ext/reflection/php_reflection.c). */
final class ReflectionClassConstantGetDocComment extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getDocComment');
    }

    public function execute(Frame $frame): void
    {
        $ctx = VmReflection::requireContext($frame);
        $receiver = ReflectionSupport::requireReflectionClassConstant($frame, $frame->calledArgs[0]);
        $loc = ReflectionSupport::classConstantSourceLocation($ctx, $receiver);
        ReflectionSupport::returnDocComment(
            $frame->returnVar,
            null !== $loc ? $loc->docComment : null
        );
    }
}
