<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\StringPregQuote;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * preg_quote() — escape regex metacharacters (subset of PHP).
 *
 * VM: {@see VmString::pregQuote()}; JIT/AOT: {@see StringPregQuote} + {@see PregQuoteJitHelper}.
 */
final class preg_quote extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('preg_quote() requires one or two arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        // Z_PARAM_STR $str — Zend 8.4 DEP+coerces null (#21234, ext/pcre/php_pcre.c).
        $subject = VmString::trimFamilyStringArgForFrame(
            $frame,
            0,
            'preg_quote',
            0,
            'str'
        );
        $delimiter = null;
        if (2 === $argc) {
            $delimiter = VmString::coerceStringBuiltinArg(
                $frame->calledArgs[1],
                'preg_quote',
                1,
                'delimiter'
            );
        }
        $frame->returnVar->string(VmString::pregQuote($subject, $delimiter));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('preg_quote() requires one or two arguments in this compiler build');
        }

        StringPregQuote::ensureLinked($context);

        $subject = JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'preg_quote', 0, 'str');
        // Empty __string__* sentinel = no delimiter (#21109 / #26827). Prefer alloc(0) over
        // null or load(constantStringFromString('')) — both segfault in user-script AOT 1-arg calls.
        $noDelimiter = $context->builder->call(
            $context->lookupFunction('__string__alloc'),
            $context->constantFromInteger(0, 'size_t')
        );
        if (1 === $argc) {
            return $context->builder->call(
                $context->lookupFunction('__string__preg_quote'),
                $subject,
                $noDelimiter
            );
        }

        return $context->builder->call(
            $context->lookupFunction('__string__preg_quote'),
            $subject,
            JitStringBuiltinArg::lower($context, $args[1], 'preg_quote', 1, 'delimiter')
        );
    }
}
