<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionParameter::isPromoted() — VM (#4848, ext/reflection/php_reflection.c). */
final class ReflectionParameterIsPromoted extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isPromoted');
    }

    public function execute(Frame $frame): void
    {
        // php-src: zim_ReflectionParameter_isPromoted — ZEND_PARSE_PARAMETERS (0 args) (#31128)
        $this->requireExactUserArgCount($frame, 'ReflectionParameter::isPromoted', 0);
        $receiver = ReflectionSupport::requireReflectionParameter($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(ReflectionSupport::parameterIsPromoted($ctx, $receiver));
        }
    }
}
