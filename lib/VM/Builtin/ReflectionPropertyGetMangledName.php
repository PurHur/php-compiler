<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionPropertyHookSupport;

/** ReflectionProperty::getMangledName() — VM (PHP 8.5+, #27592; ext/reflection/php_reflection.c). */
final class ReflectionPropertyGetMangledName extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getMangledName');
    }

    public function execute(Frame $frame): void
    {
        [$ctx, $entry, $meta, , $property] = ReflectionPropertyHookSupport::resolveProperty(
            $frame,
            $frame->calledArgs[0]
        );
        if (null !== $frame->returnVar) {
            $frame->returnVar->string(
                ReflectionPropertyHookSupport::getMangledName($entry, $meta, $property, $ctx)
            );
        }
    }
}
