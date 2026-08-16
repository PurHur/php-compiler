<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * stream_context_set_option() — singular or batch wrapper option write (ext/standard/streams.c, #3448).
 *
 * Excess/missing argc → Zend ArgumentCountError (#30584; php-src stub arity 2..4).
 * Incomplete string form → Zend ValueError (#30645); do not synthesize a null $value.
 * Soft-null `$wrapper_or_options` → E_DEPRECATED then coerce (#31422).
 */
final class stream_context_set_option extends Internal
{
    public function __construct()
    {
        parent::__construct('stream_context_set_option');
    }

    public function execute(Frame $frame): void
    {
        // php-src stub arity: 2..4 (ZEND_PARSE_PARAMETERS_START) — #30584.
        $this->requireArgCountRange($frame, 'stream_context_set_option', 2, 4);
        $argc = \count($frame->calledArgs);
        $wrapperOrOptions = VmStreamContext::normalizeWrapperOrOptionsArg(
            $frame,
            $frame->calledArgs[1]
        );
        if (2 === $argc) {
            $ok = VmStreamContext::setOption($frame->calledArgs[0], $wrapperOrOptions);
        } elseif (3 === $argc) {
            // Omit $value so setOption can distinguish missing arg #4 from an explicit null (#30645).
            $ok = VmStreamContext::setOption(
                $frame->calledArgs[0],
                $wrapperOrOptions,
                $frame->calledArgs[2]
            );
        } else {
            $ok = VmStreamContext::setOption(
                $frame->calledArgs[0],
                $wrapperOrOptions,
                $frame->calledArgs[2],
                $frame->calledArgs[3]
            );
        }
        BuiltinExecute::writeReturn(
            $frame,
            static fn (Variable $ret) => $ret->bool($ok)
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#30584).
        if (!$this->requireArgCountRangeJit($context, $args, 'stream_context_set_option', 2, 4)) {
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        return JitStreamContextSetOption::invoke($context, ...$args);
    }
}
