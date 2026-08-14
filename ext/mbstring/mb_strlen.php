<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\Variable as VMVariable;
use PHPLLVM\Value;

/**
 * mb_strlen() — UTF-8 character count (php-src ext/mbstring/mbstring.c; #158, #5695, #4405).
 *
 * Full mbstring parity (additional encodings, mb_substr, …) tracked in #4405, #3239.
 *
 * Excess argc → Zend `expects at most` ArgumentCountError (#30891).
 */
final class mb_strlen extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_strlen');
    }

    public function execute(Frame $frame): void
    {
        // php-src stub arity 1..2 — excess uses at-most wording (#30891).
        $this->requireArgCountRange($frame, 'mb_strlen', 1, 2);
        $argc = \count($frame->calledArgs);
        // Z_PARAM_STR $string — non-strict null is E_DEPRECATED + '' on 8.4 (php-src mbstring.c / #21197).
        $str = VmString::trimFamilyStringArgForFrame($frame, 0, 'mb_strlen', 0, 'string');
        if (null === $frame->returnVar) {
            return;
        }
        $internal = MbstringState::internalEncoding();
        $encoding = 2 === $argc
            ? VmMbstring::resolveValidatedEncodingArg(
                $frame->calledArgs[1],
                'mb_strlen',
                1,
                $internal
            )
            : $internal;
        $frame->returnVar->int(VmMbstring::strlen($str, $encoding));
    }

    public function call(Context $context, Variable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#30891).
        if (!$this->requireArgCountRangeJit($context, $args, 'mb_strlen', 1, 2)) {
            return $context->constantFromInteger(0, 'int64');
        }
        $argc = \count($args);
        if (Variable::TYPE_STRING === $args[0]->type && null !== ($args[0]->compileTimeString ?? null)) {
            if (1 === $argc) {
                return $context->constantFromInteger(
                    VmString::utf8CharLength($args[0]->compileTimeString),
                    'int64'
                );
            }
            if (2 === $argc
                && Variable::TYPE_STRING === $args[1]->type
                && null !== ($args[1]->compileTimeString ?? null)
            ) {
                // Fold lit+encoding before NestedJIT ABI (#27051); encodings match VmMbstring::strlen.
                $enc = $args[1]->compileTimeString;
                if ('UTF-8' === $enc) {
                    return $context->constantFromInteger(
                        VmString::utf8CharLength($args[0]->compileTimeString),
                        'int64'
                    );
                }
                if ('ASCII' === $enc || '8BIT' === $enc || 'ISO-8859-1' === $enc) {
                    return $context->constantFromInteger(
                        VmString::byteLength($args[0]->compileTimeString),
                        'int64'
                    );
                }
            }
        }

        $str = JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'mb_strlen', 0, 'string');

        if (1 === $argc) {
            return JitMbStrlen::utf8LengthFromPtr($context, $str);
        }
        if (Variable::TYPE_STRING !== $args[1]->type) {
            throw new \LogicException('mb_strlen() encoding must be a string in this compiler build');
        }
        $encoding = $args[1]->compileTimeString ?? null;
        if ('UTF-8' === $encoding) {
            return JitMbStrlen::utf8LengthFromPtr($context, $str);
        }
        if (null !== $encoding && 'ASCII' !== $encoding && '8BIT' !== $encoding && 'ISO-8859-1' !== $encoding) {
            throw new \LogicException(
                'mb_strlen() JIT only supports UTF-8, ASCII, 8BIT, or ISO-8859-1 encoding literals in this compiler build'
            );
        }

        $offset = $context->structFieldIndex($str, 'length');

        return $context->builder->load(
            $context->builder->structGep($str, $offset)
        );
    }
}
