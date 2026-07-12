<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\StringQuotemeta;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * quotemeta() — escape regex metacharacters (subset of PHP).
 *
 * VM: {@see VmString::quotemeta()}; JIT/AOT: {@see StringQuotemeta} + {@see QuotemetaJitHelper}.
 */
final class quotemeta extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('quotemeta() requires exactly one argument in this compiler build');
        }
        $str = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'quotemeta', 0, 'string');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(VmString::quotemeta($str));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('quotemeta() requires exactly one argument in this compiler build');
        }

        StringQuotemeta::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction('__string__quotemeta'),
            JitStringBuiltinArg::lower($context, $args[0], 'quotemeta', 0, 'string')
        );
    }
}
