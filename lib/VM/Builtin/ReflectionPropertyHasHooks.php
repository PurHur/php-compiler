<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionPropertyHookSupport;

/** ReflectionProperty::hasHooks() — VM (#17313, ext/reflection/php_reflection.c). */
final class ReflectionPropertyHasHooks extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('hasHooks');
    }

    public function execute(Frame $frame): void
    {
        [$ctx, $entry, $meta, , $property] = ReflectionPropertyHookSupport::resolveProperty(
            $frame,
            $frame->calledArgs[0]
        );
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(
                ReflectionPropertyHookSupport::hasHooks($entry, $meta, $property, $ctx)
            );
        }
    }
}
