<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionParameter::isDefaultValueConstant() — VM (#22026, ext/reflection/php_reflection.c). */
final class ReflectionParameterIsDefaultValueConstant extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isDefaultValueConstant');
    }

    public function execute(Frame $frame): void
    {
        // php-src: zim_ReflectionParameter_isDefaultValueConstant — ZEND_PARSE_PARAMETERS (0 args) (#31128)
        $this->requireExactUserArgCount($frame, 'ReflectionParameter::isDefaultValueConstant', 0);
        $ctx = VmReflection::requireContext($frame);
        $receiver = ReflectionSupport::requireReflectionParameter($frame, $frame->calledArgs[0]);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(
                ReflectionSupport::parameterDefaultValueIsConstantForReflection($ctx, $receiver)
            );
        }
    }
}
