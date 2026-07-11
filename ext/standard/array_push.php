<?php

declare(strict_types=1);

/**
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Builtin\ArrayPushRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * array_push() appending one or more values (subset of PHP; VM only).
 */
final class array_push extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1) {
            throw new \ArgumentCountError(\sprintf(
                'array_push() expects at least 1 argument, %d given',
                $argc
            ));
        }
        $ht = VmArray::requireArrayParam($frame->calledArgs[0], 'array_push', 1, 'array');
        $values = [];
        for ($i = 1, $n = \count($frame->calledArgs); $i < $n; ++$i) {
            $values[] = $frame->calledArgs[$i]->resolveIndirect();
        }
        $count = ArrayPushJitHelper::push($ht, ...$values);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int($count);
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1) {
            throw new \ArgumentCountError(\sprintf(
                'array_push() expects at least 1 argument, %d given',
                $argc
            ));
        }
        if (1 === \count($args) && JITVariable::TYPE_HASHTABLE === $args[0]->type) {
            return ArrayBuiltinHelper::pushMergedCallUnpack($context, $args[0]);
        }
        $array = $args[0];
        JitArrayElem::requireArrayParam($context, $array, 'array_push', 1, 'array');
        if (!JitArrayPush::requireByRefArrayArg($context, $array)) {
            return $context->constantFromInteger(0, 'int64');
        }
        $values = \array_slice($args, 1);
        if (JITVariable::TYPE_VALUE === $array->type) {
            // Object property lvalues are referencable; loadHashTable materializes boxed arrays (#1086).
            if (null !== $array->objectPropertySlot) {
                foreach ($values as $i => $arg) {
                    if (JITVariable::TYPE_STRING === $arg->type || JITVariable::TYPE_VALUE === $arg->type) {
                        $this->jitString($context, $arg, 'array_push() argument #'.((int) $i + 2));
                    }
                }

                return ArrayPushRuntime::push($context, $array, ...$values);
            }
            return JitArrayPush::pushWithValueBoxGuard(
                $context,
                $array,
                $values,
                function (Context $context, JITVariable $array, array $values): Value {
                    foreach ($values as $i => $arg) {
                        if (JITVariable::TYPE_STRING === $arg->type || JITVariable::TYPE_VALUE === $arg->type) {
                            $this->jitString($context, $arg, 'array_push() argument #'.((int) $i + 2));
                        }
                    }

                    return ArrayPushRuntime::push($context, $array, ...$values);
                }
            );
        }

        foreach ($values as $i => $arg) {
            if (JITVariable::TYPE_STRING === $arg->type || JITVariable::TYPE_VALUE === $arg->type) {
                $this->jitString($context, $arg, 'array_push() argument #'.((int) $i + 1));
            }
        }

        return ArrayPushRuntime::push($context, $array, ...$values);
    }
}
