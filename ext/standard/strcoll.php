<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\InternalStrictArg as JitInternalStrictArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPLLVM\Value;

/**
 * strcoll() — locale-aware string comparison (libc strcoll; issue #4376).
 *
 * php-src: ext/standard/string.c — PHP_FUNCTION(strcoll)
 */
final class strcoll extends Internal
{
    public function __construct()
    {
        parent::__construct('strcoll');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('strcoll() requires exactly two arguments');
        }
        InternalStrictArg::rejectNullString($frame->calledArgs[0], 'strcoll', 'string1', 0, $frame);
        InternalStrictArg::rejectNullString($frame->calledArgs[1], 'strcoll', 'string2', 1, $frame);
        $a = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'strcoll', 0, 'string1');
        $b = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'strcoll', 1, 'string2');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(VmLocaleCollate::strcoll($a, $b));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (2 !== \count($args)) {
            throw new \LogicException('strcoll() requires exactly two arguments');
        }
        JitInternalStrictArg::rejectNullString($context, $args[0], 'strcoll', 'string1', 1);
        JitInternalStrictArg::rejectNullString($context, $args[1], 'strcoll', 'string2', 2);
        $p0 = $this->stringDataPtr($context, JitStringBuiltinArg::lower($context, $args[0], 'strcoll', 0, 'string1'));
        $p1 = $this->stringDataPtr($context, JitStringBuiltinArg::lower($context, $args[1], 'strcoll', 1, 'string2'));
        $fn = $context->lookupFunction('strcoll');
        $raw = $context->builder->call($fn, $p0, $p1);
        $i64 = $context->getTypeFromString('int64');

        return $context->builder->sExt($raw, $i64);
    }
}
