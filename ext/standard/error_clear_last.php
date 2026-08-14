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
 * error_clear_last() — clear last PHP error state (ext/standard/error.c parity, issue #3158).
 *
 * Excess argc → Zend ArgumentCountError (#30674; php-src ext/standard/error.c).
 */
final class error_clear_last extends Internal
{
    public function __construct()
    {
        parent::__construct('error_clear_last');
    }

    public function execute(Frame $frame): void
    {
        // php-src stub arity: exactly 0 (#30674; ext/standard/error.c).
        $this->requireExactArgCount($frame, 'error_clear_last', 0);
        if (null !== $frame->vmContext) {
            $frame->vmContext->errors->clearLastError();
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#30674 / peer #30653).
        if (!$this->requireExactJitArgCount($context, $args, 'error_clear_last', 0)) {
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }
        JitErrorGetLast::clear($context);

        return $context->getTypeFromString('int32')->constInt(0, false);
    }
}
