<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\Func\PHP as PhpFunc;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionMethod::hasReturnType() — VM (#5141, ext/reflection/php_reflection.c). */
final class ReflectionMethodHasReturnType extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('hasReturnType');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = VmReflection::requireContext($frame);
        $receiver = ReflectionSupport::requireReflectionMethod($frame, $frame->calledArgs[0]);
        [, , $func] = ReflectionSupport::resolveReflectedMethod($ctx, $receiver);
        $frame->returnVar->bool(
            $func instanceof PhpFunc
            && ReflectionSupport::hasDeclaredReturnType($func->block->returnDeclaredType)
        );
    }
}
