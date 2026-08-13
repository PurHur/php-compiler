<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\CycleCollector;
use PHPLLVM\Value;

/**
 * gc_enabled() — whether cyclic GC is enabled (ext/standard/php_gc.c parity, #3209).
 *
 * Excess argc → Zend ArgumentCountError (#30653; php-src ext/standard/basic_functions.c).
 *
 * @see https://github.com/php/php-src/blob/master/ext/standard/php_gc.c PHP_FUNCTION(gc_enabled)
 */
final class gc_enabled extends Internal
{
    public function __construct()
    {
        parent::__construct('gc_enabled');
    }

    public function execute(Frame $frame): void
    {
        // php-src stub arity: exactly 0 (#30653; ext/standard/basic_functions.c).
        $this->requireExactArgCount($frame, 'gc_enabled', 0);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(CycleCollector::isEnabled());
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#30653 / peer #30591).
        // Dummy int1 matches isEnabled()'s native bool return (not a value-box pointer).
        if (!$this->requireExactJitArgCount($context, $args, 'gc_enabled', 0)) {
            return $context->getTypeFromString('int1')->constInt(0, false);
        }

        return JitGcToggle::isEnabled($context);
    }
}
