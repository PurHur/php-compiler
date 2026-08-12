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
 * mb_ereg() — multibyte POSIX regex match (php-src ext/mbstring/php_mbregex.c; #4635).
 */
final class mb_ereg extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_ereg');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(sprintf(
                'mb_ereg() expects at least 2 arguments, %d given',
                $argc
            ));
        }
        // Z_PARAM_STR $pattern — soft-null DEP then empty ValueError (#30067; php_mbregex.c).
        $pattern = VmMbstring::coerceMbEregPatternArg($frame, 'mb_ereg', 0);
        if (null === $frame->returnVar) {
            return;
        }
        // Z_PARAM_STR $string — null TypeError on PROFILE=8.4 (php_mbregex.c).
        $string = VmString::zparamStrBuiltinArgForFrame($frame, 1, 'mb_ereg', 1, 'string');

        $out = VmMbstring::eregMatch($pattern, $string, false);
        if (!$out['matched'] && null !== VmMbstring::mbEregRegexCompileError($pattern, false)) {
            VmMbstring::warnMbEregRegexFailure($frame, 'mb_ereg', $pattern, false);
        }

        // Always assign $regs when passed — empty array on no-match (php_mbregex.c; #26408).
        if (isset($frame->calledArgs[2])) {
            VmMbstring::writeEregRegistersArg($frame->calledArgs[2]->resolveIndirect(), $out);
        }

        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($out): void {
            $ret->bool($out['matched']);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('mb_ereg() is not lowered for JIT/AOT in this compiler build');
    }
}
