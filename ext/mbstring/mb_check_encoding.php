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
 * mb_check_encoding() — multibyte validity check (php-src ext/mbstring/mbstring.c; #4571).
 */
final class mb_check_encoding extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_check_encoding');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 2) {
            throw new \ArgumentCountError(\sprintf(
                'mb_check_encoding() expects at most 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }

        $var = null;
        $encoding = null;
        if ($argc >= 1) {
            $arg0 = $frame->calledArgs[0]->resolveIndirect();
            if (Variable::TYPE_ARRAY === $arg0->type) {
                $var = [];
                foreach ($arg0->toArray()->iterateKeyed(true) as [, $elem]) {
                    $var[] = VmString::coerceStringBuiltinArg(
                        $elem,
                        'mb_check_encoding',
                        0,
                        'var'
                    );
                }
            } elseif (Variable::TYPE_NULL === $arg0->type) {
                $var = '';
            } else {
                $var = VmString::coerceStringBuiltinArg(
                    $frame->calledArgs[0],
                    'mb_check_encoding',
                    0,
                    'var'
                );
            }
        }
        if (2 === $argc) {
            $encoding = VmMbstring::coerceEncodingString(
                $frame->calledArgs[1],
                'mb_check_encoding',
                1
            );
        }

        BuiltinExecute::writeReturn(
            $frame,
            static fn (Variable $ret) => $ret->bool(VmMbstring::checkEncoding($var, $encoding))
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc > 2) {
            throw new \LogicException('mb_check_encoding() expects at most two arguments');
        }

        $folded = JitMbCheckEncoding::tryCompileTimeFold($context, $args);
        if (null !== $folded) {
            return $folded;
        }

        return JitMbCheckEncoding::lowerRuntime($context, $args);
    }
}
