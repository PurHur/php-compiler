<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\ext\standard\VmPregMatches;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * mb_eregi() — case-insensitive multibyte regex match (php-src ext/mbstring/php_mbregex.c; #4635).
 */
final class mb_eregi extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_eregi');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(sprintf(
                'mb_eregi() expects at least 2 arguments, %d given',
                $argc
            ));
        }
        $pattern = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'mb_eregi',
            0,
            'pattern'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $string = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[1],
            'mb_eregi',
            1,
            'string'
        );

        $out = VmMbstring::eregMatch($pattern, $string, true);
        if (!$out['matched'] && null !== VmMbstring::mbEregRegexCompileError($pattern, true)) {
            VmMbstring::warnMbEregRegexFailure($frame, 'mb_eregi', $pattern, true);
        }

        if (isset($frame->calledArgs[2]) && $out['matched']) {
            $target = $frame->calledArgs[2]->resolveIndirect();
            $target->array(VmPregMatches::hostMatchesToHashTable($out['registers'], 0));
        }

        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($out): void {
            $ret->bool($out['matched']);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('mb_eregi() is not lowered for JIT/AOT in this compiler build');
    }
}
