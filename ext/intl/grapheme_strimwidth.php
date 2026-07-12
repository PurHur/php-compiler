<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\ext\mbstring\VmMbstring;
use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * grapheme_strimwidth() — grapheme-aware display-width trim (php-src ext/intl/grapheme; #9793).
 */
final class grapheme_strimwidth extends Internal
{
    public function __construct()
    {
        parent::__construct('grapheme_strimwidth');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 3 || $argc > 4) {
            throw new \ArgumentCountError(sprintf(
                'grapheme_strimwidth() expects between 3 and 4 arguments, %d given',
                $argc
            ));
        }
        $string = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'grapheme_strimwidth',
            0,
            'string'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $start = VmMath::parseIntBuiltinArg(
            $frame->calledArgs[1],
            'grapheme_strimwidth',
            2,
            'start'
        );
        $width = VmMath::parseIntBuiltinArg(
            $frame->calledArgs[2],
            'grapheme_strimwidth',
            3,
            'width'
        );
        $encoding = $argc >= 4
            ? VmMbstring::coerceEncodingArg($frame->calledArgs[3], 'grapheme_strimwidth', 3)
            : null;
        $result = VmGrapheme::strimwidth($string, $start, $width, $encoding);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
            if (false === $result) {
                $ret->bool(false);

                return;
            }
            $ret->string($result);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 3 || $argc > 4) {
            throw new \ArgumentCountError(sprintf(
                'grapheme_strimwidth() expects between 3 and 4 arguments, %d given',
                $argc
            ));
        }
        $folded = JitGrapheme::tryStrimwidthFold($context, $args);
        if (null !== $folded) {
            return $folded;
        }
        JitStringBuiltinArg::lower($context, $args[0], 'grapheme_strimwidth', 0, 'string');

        throw new \LogicException(
            'grapheme_strimwidth() JIT runtime lowering is deferred; use VM or compile-time literals (#9793)'
        );
    }
}
