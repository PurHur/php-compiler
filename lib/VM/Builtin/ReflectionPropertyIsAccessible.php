<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionProperty::isAccessible() — php-src ext/reflection/php_reflection.c (#9823). */
final class ReflectionPropertyIsAccessible extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isAccessible');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionProperty($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(ReflectionSupport::isReflectionPropertyAccessible($ctx, $receiver));
        }
    }
}
