<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionEnumBackedCase::isBacked() — VM (#5675, PHP 8.4). */
final class ReflectionEnumBackedCaseIsBacked extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isBacked');
    }

    public function execute(Frame $frame): void
    {
        ReflectionSupport::requireReflectionEnumBackedCase($frame, $frame->calledArgs[0]);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }
}
