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
 * grapheme_strpos() — grapheme index search (php-src ext/intl/grapheme; #3352).
 *
 * VM: {@see VmGrapheme}; JIT/AOT: compile-time fold via {@see JitGrapheme}.
 * Z_PARAM_STR null TypeError on 8.4 forward profile (#20694).
 */
final class grapheme_strpos extends Internal
{
    public function __construct()
    {
        parent::__construct('grapheme_strpos');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(
                'grapheme_strpos() expects at least 2 arguments, '.$argc.' given'
            );
        }
        // Z_PARAM_STR — null TypeError on 8.4 forward profile (#20694, grapheme_string.c).
        $haystack = VmString::zparamStrBuiltinArgForFrame($frame, 0, 'grapheme_strpos', 0, 'haystack');
        if (null === $frame->returnVar) {
            return;
        }
        $needle = VmString::zparamStrBuiltinArgForFrame($frame, 1, 'grapheme_strpos', 1, 'needle');
        $offset = 0;
        if (3 === $argc) {
            $offset = VmMath::parseIntBuiltinArg(
                $frame->calledArgs[2],
                'grapheme_strpos',
                3,
                'offset'
            );
        }
        $result = VmGrapheme::strpos($haystack, $needle, $offset);
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
                'grapheme_strpos() expects at least 2 arguments, '.$argc.' given'
            );
        }
        $folded = JitGrapheme::tryStrposFold($context, $args);
        if (null !== $folded) {
            return $folded;
        }
        $zparamStrict = $context->callerStrictTypes
            || JitStringBuiltinArg::requiresZparamStrStrictNullOnForwardProfile();
        // Z_PARAM_STR — null TypeError on 8.4 forward (constants + boxed VALUE) (#20694).
        JitStringBuiltinArg::lowerZparamStr($context, $args[0], 'grapheme_strpos', 0, 'haystack');
        $nullHaystack = JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false);
        if ($nullHaystack && $zparamStrict) {
            return $context->getTypeFromString('int64')->constInt(0, false);
        }
        JitStringBuiltinArg::lowerZparamStr($context, $args[1], 'grapheme_strpos', 1, 'needle');
        $nullNeedle = JITVariable::TYPE_NULL === $args[1]->type || ($args[1]->isNullConstant ?? false);
        if ($nullNeedle && $zparamStrict) {
            return $context->getTypeFromString('int64')->constInt(0, false);
        }
        if (
            (JITVariable::TYPE_VALUE === $args[0]->type || JITVariable::TYPE_VALUE === $args[1]->type)
            && $zparamStrict
        ) {
            return $context->getTypeFromString('int64')->constInt(0, false);
        }

        throw new \LogicException(
            'grapheme_strpos() JIT runtime lowering is deferred; use VM or compile-time literals (#3352)'
        );
    }
}
