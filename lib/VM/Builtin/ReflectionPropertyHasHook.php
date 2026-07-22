<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionPropertyHookSupport;

/** ReflectionProperty::hasHook() — VM (#7295, ext/reflection/php_reflection.c). */
final class ReflectionPropertyHasHook extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('hasHook');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('ReflectionProperty::hasHook() expects PropertyHookType argument');
        }
        [$ctx, $entry, $meta, , $property] = ReflectionPropertyHookSupport::resolveProperty(
            $frame,
            $frame->calledArgs[0]
        );
        $hookKind = ReflectionPropertyHookSupport::parsePropertyHookTypeArg(
            $frame->calledArgs[1],
            'ReflectionProperty::hasHook'
        );
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(
                ReflectionPropertyHookSupport::hasHook($entry, $meta, $property, $hookKind, $ctx)
            );
        }
    }
}
