<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;

/** ReflectionMethod::getDeclaringClass() — VM (#14913, ext/reflection/php_reflection.c). */
final class ReflectionMethodGetDeclaringClass extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getDeclaringClass');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionMethod($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        if (null !== $frame->returnVar) {
            $className = ReflectionSupport::classNameFromReflection($receiver);
            $out = new Variable(Variable::TYPE_OBJECT);
            $out->object(ReflectionSupport::newReflectionClassObjectForName($ctx, $className));
            $frame->returnVar->copyFrom($out);
        }
    }
}
