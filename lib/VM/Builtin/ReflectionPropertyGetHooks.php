<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionPropertyHookSupport;

/** ReflectionProperty::getHooks() — VM (#7295/#22491, ext/reflection/php_reflection.c). */
final class ReflectionPropertyGetHooks extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getHooks');
    }

    public function execute(Frame $frame): void
    {
        [$ctx, $entry, $meta, , $property] = ReflectionPropertyHookSupport::resolveProperty(
            $frame,
            $frame->calledArgs[0]
        );
        if (null === $frame->returnVar) {
            return;
        }
        $hooks = ReflectionPropertyHookSupport::getHooks($entry, $meta, $property, $ctx);
        $frame->returnVar->newArray();
        $ht = $frame->returnVar->toArray();
        foreach ($hooks as $hookKind => $methodVar) {
            $ht->add($hookKind, $methodVar);
        }
    }
}
