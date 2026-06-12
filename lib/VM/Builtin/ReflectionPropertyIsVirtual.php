<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionPropertyHookSupport;

/** ReflectionProperty::isVirtual() — VM (#7295, ext/reflection/php_reflection.c). */
final class ReflectionPropertyIsVirtual extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isVirtual');
    }

    public function execute(Frame $frame): void
    {
        [$ctx, $entry, $meta, , $property] = ReflectionPropertyHookSupport::resolveProperty(
            $frame,
            $frame->calledArgs[0]
        );
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(
                ReflectionPropertyHookSupport::isVirtual($entry, $meta, $property, $ctx)
            );
        }
    }
}
