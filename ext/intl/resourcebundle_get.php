<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * resourcebundle_get() — procedural ResourceBundle::get()
 * (php-src resourcebundle_class.c; #20814).
 *
 * Optional $fallback is accepted for php-src signature parity; lookup matches
 * ResourceBundle::get (ICU key / Version fallback).
 */
final class resourcebundle_get extends Internal
{
    public function __construct()
    {
        parent::__construct('resourcebundle_get');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'resourcebundle_get() expects between 2 and 3 arguments, %d given',
                $argc
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmResourceBundle::isResourceBundleObject($receiver->toObject())) {
            throw new \TypeError(\sprintf(
                'resourcebundle_get(): Argument #1 ($bundle) must be of type ResourceBundle, %s given',
                Variable::TYPE_OBJECT === $receiver->type
                    ? $receiver->toObject()->class->name
                    : ReflectionSupport::valueTypeLabelPublic($receiver)
            ));
        }
        $index = VmResourceBundle::coerceIndexArg($frame->calledArgs[1], 'resourcebundle_get', 1);
        $result = VmResourceBundle::get($frame->vmContext, $receiver->toObject(), $index);
        if (null === $frame->returnVar) {
            return;
        }
        if (null === $result) {
            $frame->returnVar->null();

            return;
        }
        if (\is_int($result)) {
            $frame->returnVar->int($result);

            return;
        }
        if ($result instanceof \PHPCompiler\VM\ObjectEntry) {
            $frame->returnVar->object($result);

            return;
        }
        if ($result instanceof \PHPCompiler\VM\HashTable) {
            $frame->returnVar->array($result);

            return;
        }
        $frame->returnVar->string($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('resourcebundle_get() is not implemented for JIT in this compiler build (issue #20814)');
    }
}
