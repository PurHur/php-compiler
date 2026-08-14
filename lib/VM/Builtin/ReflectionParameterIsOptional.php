<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionParameter::isOptional() — VM (#18073, ext/reflection/php_reflection.c). */
final class ReflectionParameterIsOptional extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isOptional');
    }

    public function execute(Frame $frame): void
    {
        // php-src: zim_ReflectionParameter_isOptional — ZEND_PARSE_PARAMETERS (0 args) (#31128)
        $this->requireExactUserArgCount($frame, 'ReflectionParameter::isOptional', 0);
        $ctx = VmReflection::requireContext($frame);
        $receiver = ReflectionSupport::requireReflectionParameter($frame, $frame->calledArgs[0]);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(ReflectionSupport::parameterIsOptional($ctx, $receiver));
        }
    }
}
