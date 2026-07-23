<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionParameter::getClass() — VM (#22408, ext/reflection/php_reflection.c). */
final class ReflectionParameterGetClass extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getClass');
    }

    public function execute(Frame $frame): void
    {
        $ctx = VmReflection::requireContext($frame);
        $receiver = ReflectionSupport::requireReflectionParameter($frame, $frame->calledArgs[0]);
        ReflectionSupport::emitLegacyParameterTypeApiDeprecation($frame, 'getClass');
        if (null !== $frame->returnVar) {
            $rc = ReflectionSupport::parameterGetClass($ctx, $receiver);
            if (null === $rc) {
                $frame->returnVar->null();
            } else {
                $frame->returnVar->object($rc);
            }
        }
    }
}
