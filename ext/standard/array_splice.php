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
use PHPCompiler\JIT\Builtin\ArraySpliceRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\InternalStrictArg as JitInternalStrictArg;
use PHPCompiler\JIT\NamedOptionalCallArgs;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * array_splice() — packed lists and associative arrays (LLVM packed path via #1205).
 */
final class array_splice extends Internal
{
    public function execute(Frame $frame): void
    {
        // Named length:/replacement: can leave sparse holes (php-src array.stub.php; #24824).
        $keys = \array_keys($frame->calledArgs);
        $argc = [] === $keys ? 0 : ((int) \max($keys) + 1);
        if ($argc < 2 || !\array_key_exists(0, $frame->calledArgs) || !\array_key_exists(1, $frame->calledArgs)) {
            throw new \ArgumentCountError(\sprintf(
                'array_splice() expects at least 2 arguments, %d given',
                \count($frame->calledArgs)
            ));
        }
        if ($argc > 4) {
            throw new \ArgumentCountError(\sprintf(
                'array_splice() expects at most 4 arguments, %d given',
                $argc
            ));
        }
        $arrayArg = $frame->calledArgs[0];
        VmArray::requireArrayParam($arrayArg, 'array_splice', 1, 'array');
        $arrayArg->separateArrayForWrite();
        $array = $arrayArg->resolveIndirect();
        $offsetInt = VmMath::parseIntBuiltinArgForFrame($frame, 1, 'array_splice', 2, 'offset');

        $length = null;
        if (\array_key_exists(2, $frame->calledArgs) && null !== $frame->calledArgs[2]) {
            $length = VmMath::parseNullableIntBuiltinArgForFrame($frame, 2, 'array_splice', 3, 'length');
        }

        $replacement = null;
        if (\array_key_exists(3, $frame->calledArgs) && null !== $frame->calledArgs[3]) {
            $replacementArg = $frame->calledArgs[3]->resolveIndirect();
            if (Variable::TYPE_NULL !== $replacementArg->type) {
                if (Variable::TYPE_ARRAY === $replacementArg->type) {
                    $replacement = $replacementArg->toArray();
                } else {
                    $replacement = new \PHPCompiler\VM\HashTable();
                    $copy = new Variable();
                    $copy->copyFrom($replacementArg);
                    $replacement->append($copy);
                }
            }
        }

        $removed = $array->toArray()->spliceInPlace($offsetInt, $length, $replacement);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->array($removed);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        ExceptionBridge::ensureLinked($context);
        $argc = \count($args);
        if ($argc < 2) {
            throw new \ArgumentCountError(\sprintf(
                'array_splice() expects at least 2 arguments, %d given',
                $argc
            ));
        }
        if ($argc > 4) {
            throw new \ArgumentCountError(\sprintf(
                'array_splice() expects at most 4 arguments, %d given',
                $argc
            ));
        }
        // php-src Z_PARAM_ARRAY — catchable TypeError under AOT try/catch (#27491).
        // Always via JitArrayElem → ExceptionBridge (not bare TypeErrorRaise::emitRaise).
        JitArrayElem::requireArrayParam($context, $args[0], 'array_splice', 1, 'array');

        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');
        JitInternalStrictArg::requireInt($context, $args[1], 'array_splice', 'offset', 2);
        // Z_PARAM_LONG: float + float-string precision E_DEPRECATED (#29706).
        $offset = JitIntdiv::lowerIntBuiltinArg($context, $args[1], 'array_splice', 2, 'offset', true);
        $hasLengthArg = isset($args[2]) && !NamedOptionalCallArgs::isOmittedOptional($args[2]);
        if ($hasLengthArg) {
            [$hasLength, $length] = JitIntdiv::lowerSpliceLengthArg(
                $context,
                $args[2],
                'array_splice',
                3,
                'length'
            );
        } else {
            $hasLength = $i1->constInt(0, false);
            $length = $i64->constInt(0, false);
        }
        $hasReplacement = isset($args[3]) && !NamedOptionalCallArgs::isOmittedOptional($args[3]);
        $replacement = $hasReplacement ? $args[3] : null;

        if ($args[0]->type & JITVariable::IS_NATIVE_ARRAY
            || JITVariable::TYPE_HASHTABLE === $args[0]->type
            || JITVariable::TYPE_VALUE === $args[0]->type
        ) {
            return ArraySpliceRuntime::splice(
                $context,
                $args[0],
                $offset,
                $hasLength,
                $length,
                $replacement,
                $hasReplacement
            );
        }

        // Static non-array types already raised above; poison return for SSA.
        return $context->getTypeFromString('__value__*')->constNull();
    }
}
