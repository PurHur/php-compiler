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
 * zend_version() — Zend engine version string (Zend/zend.c parity, #3359, #5304).
 *
 * Excess argc → Zend ArgumentCountError (#30628; php-src Zend/zend.c).
 */
final class zend_version extends Internal
{
    public function __construct()
    {
        parent::__construct('zend_version');
    }

    public function execute(Frame $frame): void
    {
        // php-src stub arity: exactly 0 (#30628; Zend/zend.c).
        $this->requireExactArgCount($frame, 'zend_version', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(VmInfo::zend_version());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#30628 / peer #30591).
        if (!$this->requireExactJitArgCount($context, $args, 'zend_version', 0)) {
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        return JitInfo::zend_version($context);
    }
}
