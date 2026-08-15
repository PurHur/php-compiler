<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * mb_strtolower() — multibyte lower case (php-src ext/mbstring/mbstring.c; #3239).
 *
 * Excess argc → Zend `expects at most` ArgumentCountError (#31036).
 */
final class mb_strtolower extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_strtolower');
    }

    public function execute(Frame $frame): void
    {
        // php-src stub arity 1..2 — excess uses at-most wording (#31036).
        $this->requireArgCountRange($frame, 'mb_strtolower', 1, 2);
        $argc = \count($frame->calledArgs);
        // Z_PARAM_STR $string — non-strict null is E_DEPRECATED + '' on 8.4 (php-src mbstring.c / #21282).
        $string = VmString::trimFamilyStringArgForFrame($frame, 0, 'mb_strtolower', 0, 'string');
        if (null === $frame->returnVar) {
            return;
        }
        // php-src ?string $encoding = null — explicit null uses internal encoding (#31312).
        $internal = MbstringState::internalEncoding();
        $encoding = $argc >= 2
            ? VmMbstring::resolveValidatedEncodingArg($frame->calledArgs[1], 'mb_strtolower', 1, $internal)
            : $internal;
        BuiltinExecute::writeReturn(
            $frame,
            static fn (Variable $ret) => $ret->string(VmMbstring::strtolower($string, $encoding))
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#31036).
        if (!$this->requireArgCountRangeJit($context, $args, 'mb_strtolower', 1, 2)) {
            return $context->builder->load($context->constantStringFromString(''));
        }
        $argc = \count($args);
        if (
            JITVariable::TYPE_STRING === $args[0]->type
            && null !== ($args[0]->compileTimeString ?? null)
        ) {
            $encoding = MbstringAotFoldState::internalEncoding($context) ?? MbstringState::internalEncoding();
            if ($argc >= 2) {
                $encNull = JITVariable::TYPE_NULL === $args[1]->type || $args[1]->isNullConstant;
                if ($encNull) {
                    // Same as omitted — internal encoding (#31312).
                } elseif (
                    JITVariable::TYPE_STRING === $args[1]->type
                    && null !== ($args[1]->compileTimeString ?? null)
                ) {
                    $encoding = $args[1]->compileTimeString;
                } else {
                    throw new \LogicException(
                        'mb_strtolower() JIT requires a compile-time encoding literal in this compiler build'
                    );
                }
            }
            $result = VmMbstring::strtolower($args[0]->compileTimeString, $encoding);

            return $context->builder->load($context->constantStringFromString($result));
        }

        throw new \LogicException('mb_strtolower() is not lowered for JIT/AOT in this compiler build');
    }
}
