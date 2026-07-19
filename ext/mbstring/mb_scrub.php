<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * mb_scrub() — replace invalid byte sequences (php-src ext/mbstring/mbstring.c; PHP 8.4, #6050).
 */
final class mb_scrub extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_scrub');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(\sprintf(
                'mb_scrub() expects at least 1 argument, %d given',
                $argc
            ));
        }
        // Z_PARAM_STR — null TypeError on 8.4 forward profile (#21061, mbstring.c).
        $string = VmString::coerceZparamStrBuiltinArg(
            $frame->calledArgs[0],
            'mb_scrub',
            0,
            'string'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $encoding = null;
        if (2 === $argc) {
            $encoding = VmMbstring::coerceEncodingArg(
                $frame->calledArgs[1],
                'mb_scrub',
                1
            );
        }
        $frame->returnVar->string(VmMbstring::scrub($string, $encoding));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('mb_scrub() requires one or two arguments');
        }
        // Z_PARAM_STR — null TypeError on 8.4 forward profile (#21061).
        if (JITVariable::TYPE_NULL === $args[0]->type || $args[0]->isNullConstant) {
            return JitStringBuiltinArg::lowerZparamStr($context, $args[0], 'mb_scrub', 0, 'string');
        }
        $folded = JitMbScrub::tryCompileTimeFold($context, $args);
        if (null !== $folded) {
            return $folded;
        }

        throw new \LogicException(
            'mb_scrub() JIT requires compile-time string and encoding literals in this compiler build'
        );
    }
}
