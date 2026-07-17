<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;

/** ReflectionEnumUnitCase::getEnum() — VM (#19785, ext/reflection/php_reflection.c). */
final class ReflectionEnumUnitCaseGetEnum extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getEnum');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionEnumCase($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        if (null !== $frame->returnVar) {
            $out = new Variable(Variable::TYPE_OBJECT);
            $out->object(ReflectionSupport::newReflectionEnumObjectForName(
                $ctx,
                ReflectionSupport::enumClassNameFromReflection($receiver)
            ));
            $frame->returnVar->copyFrom($out);
        }
    }
}
