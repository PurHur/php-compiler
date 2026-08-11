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
use PHPCompiler\JIT\Builtin\ArraySearchRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\JitNativeString;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * array_search() for arrays of scalar values (subset of PHP).
 *
 * php-src: ext/standard/array.stub.php / array.c — PHP_FUNCTION(array_search)
 */
final class array_search extends Internal
{
    public function execute(Frame $frame): void
    {
        // php-src zend_API.c / array.stub.php — ArgumentCountError (#28284, peer #25407).
        $this->requireArgCountRange($frame, 'array_search', 2, 3);
        $argc = \count($frame->calledArgs);
        $needle = $frame->calledArgs[0]->resolveIndirect();
        $haystack = VmArray::requireArrayParam(
            $frame->calledArgs[1],
            'array_search',
            2,
            'haystack'
        );
        // Z_PARAM_BOOL $strict — strict_types TypeError; else null→false + E_DEPRECATED (#29866).
        $strict = false;
        if (3 === $argc) {
            $strict = VmMath::parseBoolBuiltinArgForFrame($frame, 2, 'array_search', 3, 'strict');
        }
        $vm = null !== $frame->vmContext ? $frame->vmContext->runtime->vm() : null;
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->copyFrom(VmArray::searchKey($needle, $haystack, $strict, $vm));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError (AOT try/catch) — peer array_find #28284 / round #28229.
        $argc = \count($args);
        if ($argc < 2 || $argc > 3) {
            $slot = JitValueBox::alloc($context);
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                $argc < 2
                    ? \sprintf('array_search() expects at least 2 arguments, %d given', $argc)
                    : \sprintf('array_search() expects at most 3 arguments, %d given', $argc)
            );

            return $slot;
        }
        $strict = $context->constantFromBool(false);
        if (3 === $argc) {
            // Compile-time null under strict: catchable TypeError then stop IR (peer substr_compare #29756).
            if ($context->callerStrictTypes && self::isCompileTimeNull($args[2])) {
                JitNativeString::ensureInsertBlock($context);
                ExceptionBridge::emitTypeErrorAndAbort(
                    $context,
                    'array_search(): Argument #3 ($strict) must be of type bool, null given'
                );

                return $context->constantFromBool(false);
            }
            $strict = JitBoolArg::lowerCoerceZParamBool($context, $args[2], 'array_search', 'strict', 3);
        }
        if (JITVariable::TYPE_STRING === $args[0]->type || JITVariable::TYPE_VALUE === $args[0]->type) {
            $this->jitString($context, $args[0], 'array_search() needle');
        }
        JitArrayElem::requireArrayParam($context, $args[1], 'array_search', 2, 'haystack');

        return ArraySearchRuntime::search($context, $args[0], $args[1], $strict);
    }

    private static function isCompileTimeNull(JITVariable $arg): bool
    {
        return JITVariable::TYPE_NULL === $arg->type || ($arg->isNullConstant ?? false);
    }
}
