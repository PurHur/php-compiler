<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionClass::isAbstract() — VM (#6302, ext/reflection/php_reflection.c). */
final class ReflectionClassIsAbstract extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isAbstract');
    }

    public function execute(Frame $frame): void
    {
        [, $entry] = ReflectionSupport::requireReflectedClassEntry($frame, $frame->calledArgs[0]);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($entry->isAbstract || [] !== $entry->abstractMethods);
        }
    }
}
