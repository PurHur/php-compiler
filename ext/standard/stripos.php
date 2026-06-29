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
 * stripos() for two strings (subset of PHP; ASCII case fold, non-empty needle, Zend offset window).
 */
final class stripos extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('stripos() requires two or three arguments');
        }
        $haystack = $frame->calledArgs[0]->resolveIndirect();
        $needle = $frame->calledArgs[1]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        $haystackStr = VmString::coerceOperand($haystack);
        $needleStr = VmString::coerceOperand($needle);
        $offset = 0;
        if (3 === $argc) {
            $offset = VmMath::parseIntBuiltinArgForFrame($frame, 2, 'stripos', 3, 'offset');
        }
        $result = VmString::stripos($haystackStr, $needleStr, $offset);
        if (false === $result) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->int($result);
        }
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        $argc = count($args);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('stripos() requires two or three arguments');
        }
        $hay = $this->jitString($context, $args[0], 'stripos() argument #1');
        $needle = $this->jitString($context, $args[1], 'stripos() argument #2');
        $offset = 3 === $argc
            ? JitIntdiv::lowerIntBuiltinArg($context, $args[2], 'stripos', 3, 'offset')
            : null;

        return JitStrpos::find($context, $hay, $needle, $offset, true);
    }
}
