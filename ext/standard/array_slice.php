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
use PHPCompiler\JIT\Builtin\ArraySliceRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\NamedOptionalCallArgs;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * array_slice() — packed lists and preserve_keys=true (ext/standard/array.c; #4227).
 *
 * Null $preserve_keys: Z_PARAM_BOOL — strict TypeError; else DEP+coerce (#31442, re-#24693).
 */
final class array_slice extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2) {
            throw new \ArgumentCountError(\sprintf(
                'array_slice() expects at least 2 arguments, %d given',
                $argc
            ));
        }
        if ($argc > 4) {
            throw new \ArgumentCountError(\sprintf(
                'array_slice() expects at most 4 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $ht = VmArray::requireArray($frame->calledArgs[0]->resolveIndirect(), 'array_slice');
        $offsetInt = VmMath::parseIntBuiltinArgForFrame($frame, 1, 'array_slice', 2, 'offset');
        $length = null;
        if (isset($frame->calledArgs[2])) {
            $lengthArg = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_NULL !== $lengthArg->type) {
                $length = VmMath::parseIntBuiltinArgForFrame($frame, 2, 'array_slice', 3, 'length');
            }
        }
        $preserveKeys = false;
        if (isset($frame->calledArgs[3])) {
            // Z_PARAM_BOOL: caller strict_types → TypeError on null; else soft-null DEP+coerce (#31442).
            $preserveKeys = VmMath::parseBoolBuiltinArgForFrame(
                $frame,
                3,
                'array_slice',
                4,
                'preserve_keys'
            );
        }
        $frame->returnVar->array($ht->sliceCopy($offsetInt, $length, $preserveKeys));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2) {
            throw new \ArgumentCountError(\sprintf(
                'array_slice() expects at least 2 arguments, %d given',
                $argc
            ));
        }
        if ($argc > 4) {
            throw new \ArgumentCountError(\sprintf(
                'array_slice() expects at most 4 arguments, %d given',
                $argc
            ));
        }
        JitArrayElem::requireArrayArg($context, $args[0], 'array_slice');
        $hasExplicitLength = false;
        if ($argc >= 3 && !NamedOptionalCallArgs::isOmittedOptional($args[2])) {
            if (JITVariable::TYPE_NULL === $args[2]->type || $args[2]->isNullConstant) {
                $hasExplicitLength = false;
            } else {
                $hasExplicitLength = true;
            }
        }

        $offset = JitIntdiv::lowerIntBuiltinArg($context, $args[1], 'array_slice', 2, 'offset');
        $hasLength = $context->getTypeFromString('int1')->constInt($hasExplicitLength ? 1 : 0, false);
        $length = $hasExplicitLength
            ? JitIntdiv::lowerIntBuiltinArg($context, $args[2], 'array_slice', 3, 'length')
            : $context->getTypeFromString('int64')->constInt(0, false);
        // Z_PARAM_BOOL: strict TypeError on null; else null→false + E_DEPRECATED (#31442).
        $preserveKeys = ($argc >= 4 && !NamedOptionalCallArgs::isOmittedOptional($args[3]))
            ? JitBoolArg::lowerCoerceZParamBool($context, $args[3], 'array_slice', 'preserve_keys', 4)
            : null;

        return ArraySliceRuntime::slice($context, $args[0], $offset, $hasLength, $length, $preserveKeys);
    }
}
