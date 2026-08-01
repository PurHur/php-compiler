<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionClass::isInstantiable() — VM (#6302, ext/reflection/php_reflection.c). */
final class ReflectionClassIsInstantiable extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isInstantiable');
    }

    public function execute(Frame $frame): void
    {
        [, $entry, $ctx] = ReflectionSupport::requireReflectedClassEntry($frame, $frame->calledArgs[0]);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(ReflectionSupport::reflectionClassIsInstantiable($entry, $ctx));
        }
    }
}
