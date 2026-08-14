<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionParameter::isPassedByReference() — VM (#18073, ext/reflection/php_reflection.c). */
final class ReflectionParameterIsPassedByReference extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isPassedByReference');
    }

    public function execute(Frame $frame): void
    {
        // php-src: zim_ReflectionParameter_isPassedByReference — ZEND_PARSE_PARAMETERS (0 args) (#31128)
        $this->requireExactUserArgCount($frame, 'ReflectionParameter::isPassedByReference', 0);
        $ctx = VmReflection::requireContext($frame);
        $receiver = ReflectionSupport::requireReflectionParameter($frame, $frame->calledArgs[0]);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(ReflectionSupport::parameterIsPassedByReference($ctx, $receiver));
        }
    }
}
