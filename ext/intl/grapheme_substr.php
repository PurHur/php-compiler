<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

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
 * grapheme_substr() — slice by grapheme cluster index (php-src ext/intl/grapheme; #3352).
 *
 * VM: {@see VmGrapheme}; JIT/AOT: compile-time fold via {@see JitGrapheme}.
 */
final class grapheme_substr extends Internal
{
    public function __construct()
    {
        parent::__construct('grapheme_substr');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(
                'grapheme_substr() expects at least 2 arguments, '.$argc.' given'
            );
        }
        $string = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'grapheme_substr',
            0,
            'string'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $start = VmMath::parseIntBuiltinArg(
            $frame->calledArgs[1],
            'grapheme_substr',
            2,
            'start'
        );
        $length = null;
        if (3 === $argc) {
            $length = VmMath::parseIntBuiltinArg(
                $frame->calledArgs[2],
                'grapheme_substr',
                3,
                'length'
            );
        }
        $result = VmGrapheme::substr($string, $start, $length);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
            if (false === $result) {
                $ret->bool(false);
            } else {
                $ret->string($result);
            }
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(
                'grapheme_substr() expects at least 2 arguments, '.$argc.' given'
            );
        }
        $folded = JitGrapheme::trySubstrFold($context, $args);
        if (null !== $folded) {
            return $folded;
        }
        JitStringBuiltinArg::lower($context, $args[0], 'grapheme_substr', 0, 'string');

        throw new \LogicException(
            'grapheme_substr() JIT runtime lowering is deferred; use VM or compile-time literals (#3352)'
        );
    }
}
