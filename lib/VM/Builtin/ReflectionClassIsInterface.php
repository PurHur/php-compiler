<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionClass::isInterface() — VM (#18335, ext/reflection/php_reflection.c). */
final class ReflectionClassIsInterface extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isInterface');
    }

    public function execute(Frame $frame): void
    {
        [, $entry] = ReflectionSupport::requireReflectedClassEntry($frame, $frame->calledArgs[0]);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(ReflectionSupport::reflectionClassIsInterface($entry));
        }
    }
}
