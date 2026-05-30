<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;

/** ReflectionEnumUnitCase::getName() — VM (#3800). */
final class ReflectionEnumUnitCaseGetName extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getName');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionEnumUnitCase($frame, $frame->calledArgs[0]);
        $name = ReflectionSupport::enumCaseNameFromReflection($receiver);
        if (null !== $frame->returnVar) {
            $frame->returnVar->string($name);
        }
    }
}
