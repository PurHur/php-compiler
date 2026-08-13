<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * strtotime() — parse natural-language date strings to Unix timestamps (#10742).
 *
 * php-src: ext/date/php_date.c — PHP_FUNCTION(strtotime)
 * Under/over arity → Zend at-least / at-most ArgumentCountError (#30714).
 */
final class strtotime extends Internal
{
    public function __construct()
    {
        parent::__construct('strtotime');
    }

    public function execute(Frame $frame): void
    {
        // php-src stub arity: 1..2 (#30714; ext/date/php_date.stub.php).
        $this->requireArgCountRange($frame, 'strtotime', 1, 2);
        $argc = \count($frame->calledArgs);
        if (null === $frame->returnVar) {
            return;
        }
        // Soft-null on 8.4 — Zend deprecate+coerce (ext/date/php_date.c; #21208, reverts #19651 TypeError)
        $time = VmString::trimFamilyStringArgForFrame($frame, 0, 'strtotime', 0, 'datetime');
        $now = null;
        if (2 === $argc) {
            $baseVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_NULL !== $baseVar->type) {
                $now = VmDate::coerceNullableTimestampArgForFrame($frame, 1, 'strtotime', 2, 'baseTimestamp');
            }
        }
        $result = VmDateTimeNative::strtotime($time, $now);
        if (false === $result) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->int($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#30714).
        if (!$this->requireArgCountRangeJit($context, $args, 'strtotime', 1, 2)) {
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        return JitStrtotime::invoke($context, ...$args);
    }
}
