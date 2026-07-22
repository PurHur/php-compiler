<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/**
 * ReflectionFunction::isDisabled() — VM (#22165, ext/reflection/php_reflection.c).
 *
 * php-src always returns false: a disabled function cannot be queried via Reflection
 * (ReflectionException on construct), so a live ReflectionFunction is never disabled.
 */
final class ReflectionFunctionIsDisabled extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isDisabled');
    }

    public function execute(Frame $frame): void
    {
        ReflectionSupport::requireReflectionFunction($frame, $frame->calledArgs[0]);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(false);
        }
    }
}
