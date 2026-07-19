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
 * grapheme_strripos() — case-insensitive reverse grapheme index search (php-src ext/intl/grapheme; #20810).
 *
 * VM: {@see VmGrapheme}; JIT/AOT: compile-time fold via {@see JitGrapheme}.
 */
final class grapheme_strripos extends Internal
{
    public function __construct()
    {
        parent::__construct('grapheme_strripos');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(
                'grapheme_strripos() expects at least 2 arguments, '.$argc.' given'
            );
        }
        $haystack = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'grapheme_strripos',
            0,
            'haystack'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $needle = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[1],
            'grapheme_strripos',
            1,
            'needle'
        );
        $offset = 0;
        if (3 === $argc) {
            $offset = VmMath::parseIntBuiltinArg(
                $frame->calledArgs[2],
                'grapheme_strripos',
                3,
                'offset'
            );
        }
        $result = VmGrapheme::strripos($haystack, $needle, $offset);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
            if (false === $result) {
                $ret->bool(false);
            } else {
                $ret->int($result);
            }
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(
                'grapheme_strripos() expects at least 2 arguments, '.$argc.' given'
            );
        }
        $folded = JitGrapheme::tryStrriposFold($context, $args);
        if (null !== $folded) {
            return $folded;
        }
        JitStringBuiltinArg::lower($context, $args[0], 'grapheme_strripos', 0, 'haystack');
        JitStringBuiltinArg::lower($context, $args[1], 'grapheme_strripos', 1, 'needle');

        throw new \LogicException(
            'grapheme_strripos() JIT runtime lowering is deferred; use VM or compile-time literals (#20810)'
        );
    }
}
