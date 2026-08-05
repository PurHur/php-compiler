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
 * grapheme_extract() — extract grapheme clusters from UTF-8 (php-src ext/intl/grapheme; #6023).
 *
 * VM: {@see VmGrapheme}; JIT: compile-time fold via {@see JitGrapheme::tryExtractFold} (#6023, #19965).
 * Reflection / named args: Zend stub `$haystack`/`$type`/`$offset`/`&$next` → `string|false` (#27884).
 */
final class grapheme_extract extends Internal
{
    public function __construct()
    {
        parent::__construct('grapheme_extract');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 5) {
            throw new \LogicException('grapheme_extract() requires two to five arguments');
        }
        $haystack = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'grapheme_extract',
            0,
            'haystack'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $size = VmMath::parseIntBuiltinArg(
            $frame->calledArgs[1],
            'grapheme_extract',
            2,
            'size'
        );
        $extractType = VmGrapheme::EXTR_COUNT;
        if ($argc >= 3) {
            $extractType = VmMath::parseIntBuiltinArg(
                $frame->calledArgs[2],
                'grapheme_extract',
                3,
                'type'
            );
        }
        $start = 0;
        if ($argc >= 4) {
            $start = VmMath::parseIntBuiltinArg(
                $frame->calledArgs[3],
                'grapheme_extract',
                4,
                'offset'
            );
        }
        $nextVar = null;
        if ($argc >= 5) {
            $nextVar = $frame->calledArgs[4];
            if (!$nextVar->isIndirect()) {
                BuiltinExecute::writeReturn($frame, static function (Variable $ret): void {
                    $ret->bool(false);
                });

                return;
            }
        }

        $next = $start;
        if (null !== $nextVar) {
            $target = $nextVar->resolveIndirect();
            $target->int($start);
        }

        $result = VmGrapheme::extract($haystack, $size, $extractType, $start, $next);
        if (false === $result) {
            BuiltinExecute::writeReturn($frame, static function (Variable $ret): void {
                $ret->bool(false);
            });

            return;
        }
        if (null !== $nextVar) {
            $target = $nextVar->resolveIndirect();
            $target->int($next);
        }
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
            $ret->string($result);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 5) {
            throw new \LogicException('grapheme_extract() requires two to five arguments');
        }
        $folded = JitGrapheme::tryExtractFold($context, $args);
        if (null !== $folded) {
            return $folded;
        }
        if ($argc >= 1) {
            JitStringBuiltinArg::lower($context, $args[0], 'grapheme_extract', 0, 'haystack');
        }

        throw new \LogicException(
            'grapheme_extract() JIT runtime lowering is deferred; use VM or compile-time literals (#6023)'
        );
    }
}
