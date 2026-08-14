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
 * error_get_last() — last PHP error state (ext/standard/error.c parity, issue #3158).
 *
 * Excess argc → Zend ArgumentCountError (#30674; php-src ext/standard/error.c).
 */
final class error_get_last extends Internal
{
    public function __construct()
    {
        parent::__construct('error_get_last');
    }

    public function execute(Frame $frame): void
    {
        // php-src stub arity: exactly 0 (#30674; ext/standard/error.c).
        $this->requireExactArgCount($frame, 'error_get_last', 0);
        if (null === $frame->returnVar || null === $frame->vmContext) {
            return;
        }
        $frame->returnVar->copyFrom($frame->vmContext->errors->getLastErrorVariable());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#30674 / peer #30653).
        if (!$this->requireExactJitArgCount($context, $args, 'error_get_last', 0)) {
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        return JitErrorGetLast::invoke($context);
    }
}
