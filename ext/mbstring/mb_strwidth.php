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
 * mb_strwidth() — terminal display width (php-src ext/mbstring/mbstring.c; #3495).
 */
final class mb_strwidth extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_strwidth');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('mb_strwidth() requires one or two arguments');
        }
        // Z_PARAM_STR $string — non-strict null is E_DEPRECATED + '' on 8.4 (php-src mbstring.c; #24257).
        $string = VmString::trimFamilyStringArgForFrame($frame, 0, 'mb_strwidth', 0, 'string');
        if (null === $frame->returnVar) {
            return;
        }
        $encoding = 2 === $argc
            ? VmMbstring::coerceEncodingArg($frame->calledArgs[1], 'mb_strwidth', 1)
            : 'UTF-8';
        BuiltinExecute::writeReturn(
            $frame,
            static fn (Variable $ret) => $ret->int(VmMbstring::strwidth($string, $encoding))
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitMbStrwidth::strwidth($context, ...$args);
    }
}
