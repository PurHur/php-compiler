<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionClass::isCloneable() — VM (#22109, ext/reflection/php_reflection.c). */
final class ReflectionClassIsCloneable extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isCloneable');
    }

    public function execute(Frame $frame): void
    {
        [, $entry, $ctx] = ReflectionSupport::requireReflectedClassEntry($frame, $frame->calledArgs[0]);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(VmReflection::reflectionClassIsCloneable($entry, $ctx));
        }
    }
}
