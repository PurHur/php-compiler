<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionPropertyHookSupport;

/** ReflectionProperty::getHook(PropertyHookType) — VM (#4806, ext/reflection/php_reflection.c). */
final class ReflectionPropertyGetHook extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getHook');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('ReflectionProperty::getHook() expects PropertyHookType');
        }
        [$ctx, $entry, $meta, , $property] = ReflectionPropertyHookSupport::resolveProperty(
            $frame,
            $frame->calledArgs[0]
        );
        $hookKind = ReflectionPropertyHookSupport::parsePropertyHookTypeArg(
            $frame->calledArgs[1],
            'ReflectionProperty::getHook'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $rm = ReflectionPropertyHookSupport::hookReflectionMethod(
            $ctx,
            $entry,
            $meta,
            $property,
            $hookKind
        );
        if (null === $rm) {
            $frame->returnVar->null();

            return;
        }
        $frame->returnVar->copyFrom($rm);
    }
}
