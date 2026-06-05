<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** rawurldecode() for strings (subset of PHP; JIT/AOT via __string__rawurldecode). */
final class rawurldecode extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('rawurldecode() requires exactly one argument');
        }
        $subject = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'rawurldecode',
            0,
            'string'
        );
        BuiltinExecute::writeReturn(
            $frame,
            static fn (Variable $ret) => $ret->string(VmString::rawurldecode($subject))
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('rawurldecode() requires exactly one argument');
        }

        $str = JitStringBuiltinArg::lower($context, $args[0], 'rawurldecode', 0, 'string');

        return JitUrlencode::rawurldecode($context, $str);
    }

}
