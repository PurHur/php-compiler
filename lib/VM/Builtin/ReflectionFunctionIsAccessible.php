<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionFunction::isAccessible() — php-src ext/reflection/php_reflection.c (#9823). */
final class ReflectionFunctionIsAccessible extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isAccessible');
    }

    public function execute(Frame $frame): void
    {
        ReflectionSupport::requireReflectionFunction($frame, $frame->calledArgs[0]);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(ReflectionSupport::isReflectionFunctionAccessible());
        }
    }
}
