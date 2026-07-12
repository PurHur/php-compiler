<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;

/** ReflectionConstant::getDeclaringClass() — VM (#17343, ext/reflection/php_reflection.c). */
final class ReflectionConstantGetDeclaringClass extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getDeclaringClass');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionConstant($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        if (null !== $frame->returnVar) {
            $className = ReflectionSupport::classNameFromReflection($receiver);
            $entry = VmReflection::resolveClassEntry($ctx, $className);
            if (null === $entry) {
                ReflectionSupport::throwReflectionException(
                    'Cannot get declaring class for a global constant'
                );
            }
            $out = new Variable(Variable::TYPE_OBJECT);
            $out->object(ReflectionSupport::newReflectionClassObjectForName($ctx, $entry->name));
            $frame->returnVar->copyFrom($out);
        }
    }
}
