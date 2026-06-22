<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Value\Function_;
use PHPCompiler\Block;
use PHPCompiler\JIT;
use PHPCompiler\OpCode;

/**
 * JIT/AOT lowering for eval() / TYPE_EVAL — thin trampoline to {@see Builtin\EvalRuntime} (#10248).
 *
 * php-src: ext/standard/basic_functions.c — zif_eval / zend_eval_stringl
 */
final class EvalHelper
{
    public static function compile(
        JIT $jit,
        Function_ $func,
        Block $callerBlock,
        OpCode $op
    ): void {
        Builtin\EvalRuntime::compile($jit, $func, $callerBlock, $op);
    }
}
