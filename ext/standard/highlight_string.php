<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * highlight_string() — syntax-highlighted PHP as HTML (VM: HighlightEngine, #4824).
 *
 * @see https://github.com/php/php-src/blob/master/ext/standard/url.c PHP_FUNCTION(highlight_string)
 */
final class highlight_string extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('highlight_string() expects 1 or 2 arguments in this compiler build');
        }
        $code = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            $this->getName(),
            0,
            'string'
        );
        $return = false;
        if ($argc >= 2) {
            $return = VmHighlight::resolveReturnFlag($frame->calledArgs[1], $this->getName());
        }
        $result = VmHighlight::highlightString($code, $return);
        if (null === $frame->returnVar) {
            return;
        }
        if ($return) {
            $frame->returnVar->string((string) $result);

            return;
        }
        $frame->returnVar->bool((bool) $result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitHighlight::highlightString($context, ...$args);
    }
}
