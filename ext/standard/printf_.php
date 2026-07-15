<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\OutputBuffer;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * printf() — formatted write to SAPI output (VM; JIT/AOT via __compiler_printf, issue #3681).
 *
 * php-src: ext/standard/formatted_print.c — PHP_FUNCTION(printf)
 */
final class printf_ extends Internal
{
    public function __construct()
    {
        parent::__construct('printf');
    }

    public function execute(Frame $frame): void
    {
        $this->requireAtLeastArgCount($frame, 'printf', 1);
        $format = VmString::stringBuiltinArgForFrame($frame, 0, 'printf', 0, 'format');
        $argc = \count($frame->calledArgs);
        $values = [];
        for ($i = 1; $i < $argc; ++$i) {
            $values[] = $frame->calledArgs[$i]->resolveIndirect();
        }
        $out = VmSprintf::format($format, $values, $frame);
        OutputBuffer::append($out);
        if (null !== $frame->returnVar) {
            $frame->returnVar->int(VmString::byteLength($out));
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (!$this->requireAtLeastJitArgCount($context, $args, 'printf', 1)) {
            return $context->constantFromInteger(0, 'int64');
        }

        return JitPrintf::format($context, ...$args);
    }
}
