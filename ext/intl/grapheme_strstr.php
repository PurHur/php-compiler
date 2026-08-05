<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

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
 * grapheme_strstr() — grapheme-cluster strstr (php-src ext/intl/grapheme; #7221).
 *
 * VM: {@see VmGrapheme}; JIT/AOT: compile-time fold via {@see JitGrapheme}.
 * Reflection / named args: Zend stub `$beforeNeedle` (not `$part`) → `string|false` (#27884).
 */
final class grapheme_strstr extends Internal
{
    public function __construct()
    {
        parent::__construct('grapheme_strstr');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(
                'grapheme_strstr() expects at least 2 arguments, '.$argc.' given'
            );
        }
        $haystack = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'grapheme_strstr',
            0,
            'haystack'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $needle = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[1],
            'grapheme_strstr',
            1,
            'needle'
        );
        $beforeNeedle = false;
        if (3 === $argc) {
            $flag = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_BOOLEAN !== $flag->type) {
                throw new \LogicException(
                    'grapheme_strstr() before_needle must be a boolean in this compiler build'
                );
            }
            $beforeNeedle = $flag->toBool();
        }
        $result = VmGrapheme::strstr($haystack, $needle, $beforeNeedle);
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
                'grapheme_strstr() expects at least 2 arguments, '.$argc.' given'
            );
        }
        $folded = JitGrapheme::tryStrstrFold($context, $args, false);
        if (null !== $folded) {
            return $folded;
        }
        JitStringBuiltinArg::lower($context, $args[0], 'grapheme_strstr', 0, 'haystack');
        JitStringBuiltinArg::lower($context, $args[1], 'grapheme_strstr', 1, 'needle');

        throw new \LogicException(
            'grapheme_strstr() JIT runtime lowering is deferred; use VM or compile-time literals (#7221)'
        );
    }
}
