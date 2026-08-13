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
 * preg_last_error() — VM via host PCRE; JIT/AOT via __compiler_preg_last_error (issue #1181).
 *
 * Excess argc → Zend ArgumentCountError (#30628; php-src ext/pcre/php_pcre.c).
 */
final class preg_last_error_ extends Internal
{
    public function __construct()
    {
        parent::__construct('preg_last_error');
    }

    public function execute(Frame $frame): void
    {
        // php-src stub arity: exactly 0 (#30628; ext/pcre/php_pcre.c).
        $this->requireExactArgCount($frame, 'preg_last_error', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(VmPreg::lastError());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#30628 / peer #30591).
        if (!$this->requireExactJitArgCount($context, $args, 'preg_last_error', 0)) {
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        return JitPregLastError::invoke($context);
    }
}
