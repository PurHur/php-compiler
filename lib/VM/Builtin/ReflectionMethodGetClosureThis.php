<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;

/** ReflectionMethod::getClosureThis() — VM (#14614, ext/reflection/php_reflection.c). */
final class ReflectionMethodGetClosureThis extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getClosureThis');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionMethod($frame, $frame->calledArgs[0]);
        if (null === $frame->returnVar) {
            return;
        }
        $state = $receiver->reflectionClosureState;
        if (null === $state || null === $state->boundThis) {
            $frame->returnVar->null();

            return;
        }
        $bound = $state->boundThis->resolveIndirect();
        if (Variable::TYPE_OBJECT === $bound->type) {
            $frame->returnVar->copyFrom($bound);

            return;
        }
        $frame->returnVar->null();
    }
}
