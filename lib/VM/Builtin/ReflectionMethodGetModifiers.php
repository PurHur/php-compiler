<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionMethod::getModifiers() — VM (#7116, ext/reflection/php_reflection.c). */
final class ReflectionMethodGetModifiers extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getModifiers');
    }

    public function execute(Frame $frame): void
    {
        // php-src: zim_ReflectionMethod_getModifiers — ZEND_PARSE_PARAMETERS (0 args) (#31127)
        $this->requireExactUserArgCount($frame, 'ReflectionMethod::getModifiers', 0);
        $receiver = ReflectionSupport::requireReflectionMethod($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        $flags = ReflectionSupport::reflectedMethodCfgFlags($ctx, $receiver);
        if (null !== $frame->returnVar) {
            $frame->returnVar->int(
                VmReflection::cfgMethodFlagsToReflectionModifiers($flags)
            );
        }
    }
}
