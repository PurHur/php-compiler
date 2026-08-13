<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * mb_str_split() — multibyte string to array (php-src ext/mbstring/mbstring.c; #3299, #26870).
 *
 * Zend Z_PARAM_STR soft-null + DEP on $string under PROFILE=8.4 (not TypeError) — #24207,
 * peer #24176 / #24209 (mb_trim / mb_convert_kana family).
 *
 * JIT/AOT: {@see JitMbStrSplit} → NestedJIT {@see MbStrSplitJitHelper} (#26870).
 * Excess argc → Zend `expects at most` ArgumentCountError (#30786).
 */
final class mb_str_split extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_str_split');
    }

    public function execute(Frame $frame): void
    {
        // php-src stub arity 1..3 — excess uses at-most wording (#30786).
        $this->requireArgCountRange($frame, 'mb_str_split', 1, 3);
        $argc = \count($frame->calledArgs);
        // Zend 8.4 ZPP soft-null + DEP (not TypeError) — #24207, peer #24176.
        $string = VmString::trimFamilyStringArgForFrame($frame, 0, 'mb_str_split', 0, 'string');
        if (null === $frame->returnVar) {
            return;
        }
        $length = 1;
        if ($argc >= 2) {
            $length = VmMbstring::coerceLengthArg($frame, 'mb_str_split', 1);
        }
        $encoding = $argc >= 3
            ? VmMbstring::coerceEncodingArg($frame->calledArgs[2], 'mb_str_split', 2)
            : 'UTF-8';
        $result = VmMbstring::strSplit($string, $length, $encoding);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
            $ret->array(MbstringState::hashTableFromStringList($result));
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#30786).
        if (!$this->requireArgCountRangeJit($context, $args, 'mb_str_split', 1, 3)) {
            return HashTableHelper::alloc($context);
        }

        return JitMbStrSplit::invoke($context, ...$args);
    }
}
