<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** rawurlencode() for strings (subset of PHP; JIT/AOT via __string__rawurlencode). */
final class rawurlencode extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('rawurlencode() requires exactly one argument');
        }
        $subject = VmString::stringBuiltinArgForFrame(
            $frame,
            0,
            'rawurlencode',
            0,
            'string'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(VmString::rawurlencode($subject));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('rawurlencode() requires exactly one argument');
        }

        $str = JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[0], 'rawurlencode', 0, 'string');

        return JitUrlencode::rawurlencode($context, $str);
    }

}
