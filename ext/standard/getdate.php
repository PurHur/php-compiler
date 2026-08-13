<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * getdate() — associative date/time breakdown (VM VmDate; JIT/AOT StringGetdate LLVM, #5256).
 *
 * Excess argc → Zend ArgumentCountError wording (#30714; php-src ext/date/php_date.c).
 */
final class getdate extends Internal
{
    public function __construct()
    {
        parent::__construct('getdate');
    }

    public function execute(Frame $frame): void
    {
        // php-src stub arity: 0..1 (#30714; ext/date/php_date.stub.php).
        $this->requireAtMostArgCount($frame, 'getdate', 1);
        $argc = \count($frame->calledArgs);
        if (null === $frame->returnVar) {
            return;
        }
        $timestamp = null;
        if (1 === $argc) {
            $timestamp = VmDate::coerceNullableTimestampArgForFrame($frame, 0, 'getdate', 1, 'timestamp');
        }
        $frame->returnVar->array(VmDate::getdate($timestamp));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#30714).
        if (!$this->requireAtMostJitArgCount($context, $args, 'getdate', 1)) {
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        return JitGetdate::invoke($context, $args[0] ?? null);
    }
}
