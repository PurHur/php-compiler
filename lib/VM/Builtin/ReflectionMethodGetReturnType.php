<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\Func\PHP as PhpFunc;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\ReflectionTypeSupport;

/** ReflectionMethod::getReturnType() — VM (#6597, #25406; ext/reflection/php_reflection.c). */
final class ReflectionMethodGetReturnType extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getReturnType');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = VmReflection::requireContext($frame);
        $receiver = ReflectionSupport::requireReflectionMethod($frame, $frame->calledArgs[0]);
        [$declaring, $methodLc] = ReflectionSupport::resolveReflectedMethodDeclaring($ctx, $receiver);
        $declared = null;
        $func = $declaring->methods[$methodLc] ?? null;
        if ($func instanceof PhpFunc) {
            $declared = $func->block->returnDeclaredType;
        } elseif (isset($declaring->methodReturnDeclaredTypes[$methodLc])) {
            $declared = $declaring->methodReturnDeclaredTypes[$methodLc];
        }
        if (null === $declared || !ReflectionSupport::hasDeclaredReturnType($declared)) {
            $frame->returnVar->null();

            return;
        }
        $frame->returnVar->copyFrom(ReflectionTypeSupport::buildTypeVariable($ctx, $declared));
    }
}
