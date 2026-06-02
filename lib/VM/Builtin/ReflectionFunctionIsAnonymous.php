<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionFunction::isAnonymous() — VM (#4123). */
final class ReflectionFunctionIsAnonymous extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isAnonymous');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionFunction($frame, $frame->calledArgs[0]);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(ReflectionSupport::isReflectionFunctionAnonymous($receiver));
        }
    }
}
