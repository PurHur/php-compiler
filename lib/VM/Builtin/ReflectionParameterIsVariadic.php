<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionParameter::isVariadic() — VM (#4385, #24461, ext/reflection/php_reflection.c). */
final class ReflectionParameterIsVariadic extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isVariadic');
    }

    public function execute(Frame $frame): void
    {
        // php-src: zim_ReflectionParameter_isVariadic — ZEND_PARSE_PARAMETERS (0 args) (#31128)
        $this->requireExactUserArgCount($frame, 'ReflectionParameter::isVariadic', 0);
        $ctx = VmReflection::requireContext($frame);
        $receiver = ReflectionSupport::requireReflectionParameter($frame, $frame->calledArgs[0]);
        if (null !== $frame->returnVar) {
            // Internals must not resolve a user Block (#24461, zim_reflection_parameter_isVariadic).
            $frame->returnVar->bool(
                ReflectionSupport::parameterIsVariadicForReflection($ctx, $receiver)
            );
        }
    }
}
