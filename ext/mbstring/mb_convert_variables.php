<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\ext\iconv\CharsetEngine;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * mb_convert_variables() — in-place charset conversion (php-src ext/mbstring/mbstring.c; #4572).
 */
final class mb_convert_variables extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_convert_variables');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 3) {
            throw new \ArgumentCountError(\sprintf(
                'mb_convert_variables() expects at least 3 arguments, %d given',
                $argc
            ));
        }
        $to = VmMbstring::coerceEncodingString($frame->calledArgs[0], 'mb_convert_variables', 0);
        if (!VmMbstring::isMbConvertPseudoEncoding($to) && null === CharsetEngine::parseEncodingSpec($to)) {
            throw new \ValueError(\sprintf(
                'mb_convert_variables(): Argument #1 ($to_encoding) is not a supported encoding, "%s" given',
                $to
            ));
        }
        $fromList = VmMbConvertVariables::coerceFromEncodingList(
            $frame->calledArgs[1],
            'mb_convert_variables',
            1
        );
        $vars = [];
        for ($i = 2; $i < $argc; ++$i) {
            $vars[] = $frame->calledArgs[$i];
        }
        $detected = VmMbConvertVariables::convert($frame, $to, $fromList, $vars);
        if (null === $frame->returnVar) {
            return;
        }
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($detected): void {
            if (false === $detected) {
                $ret->bool(false);

                return;
            }
            $ret->string($detected);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('mb_convert_variables() is not lowered for JIT/AOT in this compiler build');
    }
}
