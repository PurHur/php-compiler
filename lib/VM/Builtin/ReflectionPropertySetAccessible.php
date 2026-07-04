<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionProperty::setAccessible() — php-src ext/reflection/php_reflection.c (#9823). */
final class ReflectionPropertySetAccessible extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('setAccessible');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionProperty($frame, $frame->calledArgs[0]);
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'ReflectionProperty::setAccessible() expects exactly 1 argument, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        $accessible = $frame->calledArgs[1]->resolveIndirect()->toBool();
        ReflectionSupport::setReflectionAccessible($receiver, $accessible);
    }
}
