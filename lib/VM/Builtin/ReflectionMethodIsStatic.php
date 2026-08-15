<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCfg\Func as CfgFunc;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionMethod::isStatic() — VM (#7116, ext/reflection/php_reflection.c). */
final class ReflectionMethodIsStatic extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isStatic');
    }

    public function execute(Frame $frame): void
    {
        // php-src: zim_ReflectionFunctionAbstract_isStatic — ZEND_PARSE_PARAMETERS (0 args) (#31127)
        $this->requireExactUserArgCount($frame, 'ReflectionFunctionAbstract::isStatic', 0);
        $receiver = ReflectionSupport::requireReflectionMethod($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        $flags = ReflectionSupport::reflectedMethodCfgFlags($ctx, $receiver);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(($flags & CfgFunc::FLAG_STATIC) !== 0);
        }
    }
}
