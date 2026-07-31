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
 * mb_strtoupper() — multibyte upper case (php-src ext/mbstring/mbstring.c; #3239).
 */
final class mb_strtoupper extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_strtoupper');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(sprintf(
                'mb_strtoupper() expects at least 1 argument, %d given',
                $argc
            ));
        }
        // Z_PARAM_STR $string — non-strict null is E_DEPRECATED + '' on 8.4 (php-src mbstring.c / #21313).
        $string = VmString::trimFamilyStringArgForFrame($frame, 0, 'mb_strtoupper', 0, 'string');
        if (null === $frame->returnVar) {
            return;
        }
        $encoding = $argc >= 2
            ? VmMbstring::coerceMbEncodingNameArg($frame->calledArgs[1], 'mb_strtoupper', 1)
            : MbstringState::internalEncoding();
        BuiltinExecute::writeReturn(
            $frame,
            static fn (Variable $ret) => $ret->string(VmMbstring::strtoupper($string, $encoding))
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('mb_strtoupper() requires one or two arguments');
        }
        if (
            JITVariable::TYPE_STRING === $args[0]->type
            && null !== ($args[0]->compileTimeString ?? null)
        ) {
            $encoding = 'UTF-8';
            if ($argc >= 2) {
                if (
                    JITVariable::TYPE_STRING !== $args[1]->type
                    || null === ($args[1]->compileTimeString ?? null)
                ) {
                    throw new \LogicException(
                        'mb_strtoupper() JIT requires a compile-time encoding literal in this compiler build'
                    );
                }
                $encoding = $args[1]->compileTimeString;
            }
            $result = VmMbstring::strtoupper($args[0]->compileTimeString, $encoding);

            return $context->builder->load($context->constantStringFromString($result));
        }

        throw new \LogicException('mb_strtoupper() is not lowered for JIT/AOT in this compiler build');
    }
}
