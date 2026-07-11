<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionMethod::isAccessible() — php-src ext/reflection/php_reflection.c (#9823). */
final class ReflectionMethodIsAccessible extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isAccessible');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionMethod($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(ReflectionSupport::isReflectionMethodAccessible($ctx, $receiver));
        }
    }
}
