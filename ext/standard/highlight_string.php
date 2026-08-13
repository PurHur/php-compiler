<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * highlight_string() — syntax-highlighted PHP as HTML (VM: HighlightEngine, #4824).
 *
 * Excess/missing argc → Zend ArgumentCountError (#30723; php-src ext/standard/url_scanner_ex.re).
 *
 * @see https://github.com/php/php-src/blob/master/ext/standard/url_scanner_ex.re PHP_FUNCTION(highlight_string)
 */
final class highlight_string extends Internal
{
    public function execute(Frame $frame): void
    {
        // php-src stub arity: 1..2 (#30723; ext/standard/basic_functions.stub.php).
        $this->requireArgCountRange($frame, 'highlight_string', 1, 2);
        $argc = \count($frame->calledArgs);
        $code = VmString::stringBuiltinArgForFrame(
            $frame,
            0,
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
        // Catchable ArgumentCountError under AOT try/catch (#30723).
        if (!$this->requireArgCountRangeJit($context, $args, 'highlight_string', 1, 2)) {
            return $context->getTypeFromString('__value__*')->constNull();
        }
        // Z_PARAM_STR — null TypeError on 8.4 forward profile (#20262).
        // Emit TypeError+abort without linking highlight helper (AOT IR; #20658 pattern).
        if (
            (JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false))
            && ($context->callerStrictTypes || JitStringBuiltinArg::requiresZparamStrStrictNullOnForwardProfile())
        ) {
            JitStringBuiltinArg::lowerZparamStr($context, $args[0], 'highlight_string', 0, 'string');

            return $context->getTypeFromString('__value__*')->constNull();
        }

        return JitHighlight::highlightString($context, ...$args);
    }
}
