<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionMethod::isInternal() — VM (#18228, ext/reflection/php_reflection.c). */
final class ReflectionMethodIsInternal extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isInternal');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionMethod($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(ReflectionSupport::isReflectionMethodInternal($ctx, $receiver));
        }
    }
}
