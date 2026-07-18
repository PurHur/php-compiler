<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionPropertyHookSupport;

/** ReflectionProperty::isFinal() — VM (#20511, ext/reflection/php_reflection.c). */
final class ReflectionPropertyIsFinal extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isFinal');
    }

    public function execute(Frame $frame): void
    {
        [$ctx, $entry, $meta, , $property] = ReflectionPropertyHookSupport::resolveProperty(
            $frame,
            $frame->calledArgs[0]
        );
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(
                ReflectionPropertyHookSupport::isFinal($entry, $meta, $property, $ctx)
            );
        }
    }
}
