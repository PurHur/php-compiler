<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * mb_convert_kana() — Japanese kana width conversion (php-src ext/mbstring/mbstring.c; #13099).
 *
 * Zend Z_PARAM_STR soft-null + DEP on $string/$mode (not TypeError) under PROFILE=8.4 — #24209,
 * peer #24176 (mb_trim / mb_ucfirst family).
 */
final class mb_convert_kana extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_convert_kana');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 3) {
            throw new \ArgumentCountError(sprintf(
                'mb_convert_kana() expects between 1 and 3 arguments, %d given',
                $argc
            ));
        }
        // Zend 8.4 ZPP soft-null + DEP (not TypeError) — #24209, peer #24176.
        $str = VmString::trimFamilyStringArgForFrame($frame, 0, 'mb_convert_kana', 0, 'string');
        $option = null;
        if ($argc >= 2) {
            $option = VmString::trimFamilyStringArgForFrame($frame, 1, 'mb_convert_kana', 1, 'mode');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $encoding = 'UTF-8';
        if (3 === $argc) {
            $encoding = VmMbstring::coerceEncodingArg(
                $frame->calledArgs[2],
                'mb_convert_kana',
                2
            );
        }

        $frame->returnVar->string(KanaConvert::convert($str, $option, $encoding));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'mb_convert_kana() JIT is not supported in this compiler build'
        );
    }
}
