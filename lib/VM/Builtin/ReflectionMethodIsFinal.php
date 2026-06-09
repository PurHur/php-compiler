<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCfg\Func as CfgFunc;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionMethod::isFinal() — VM (#7116, ext/reflection/php_reflection.c). */
final class ReflectionMethodIsFinal extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isFinal');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionMethod($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        $flags = ReflectionSupport::reflectedMethodCfgFlags($ctx, $receiver);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(($flags & CfgFunc::FLAG_FINAL) !== 0);
        }
    }
}
