<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\OutputBuffer;
use PHPLLVM\Value;

/**
 * flush() — fflush SAPI write buffer (VM; JIT/AOT via __phpc_flush, issue #3388).
 *
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(flush); output.c — php_flush
 */
final class flush_ extends Internal
{
    public function __construct()
    {
        parent::__construct('flush');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'flush', 0);
        OutputBuffer::flush();
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitFlush::invoke($context, ...$args);
    }
}
