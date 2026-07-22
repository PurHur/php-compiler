<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionFunction::getDocComment() — VM (#22144, ext/reflection/php_reflection.c). */
final class ReflectionFunctionGetDocComment extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getDocComment');
    }

    public function execute(Frame $frame): void
    {
        $ctx = VmReflection::requireContext($frame);
        $receiver = ReflectionSupport::requireReflectionFunction($frame, $frame->calledArgs[0]);
        if (null === $frame->returnVar) {
            return;
        }
        if (ReflectionSupport::isReflectionInternalFunction($receiver)) {
            $frame->returnVar->bool(false);

            return;
        }
        $loc = ReflectionSupport::functionSourceLocation($ctx, $receiver);
        ReflectionSupport::returnDocComment($frame->returnVar, $loc?->docComment);
    }
}
