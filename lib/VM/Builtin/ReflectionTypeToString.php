<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** Reflection*Type::__toString() — VM (#3355). */
final class ReflectionTypeToString extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__tostring');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionType($frame, $frame->calledArgs[0]);
        if (null !== $frame->returnVar) {
            $frame->returnVar->string(ReflectionSupport::typeStringFromReflection($receiver));
        }
    }
}
