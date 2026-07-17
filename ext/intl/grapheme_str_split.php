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
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * grapheme_str_split() — split string into grapheme clusters (php-src ext/intl/grapheme; #5958).
 *
 * VM: {@see VmGrapheme}; JIT: compile-time fold via {@see JitGrapheme::tryStrSplitFold} (#5958, #6246).
 */
final class grapheme_str_split extends Internal
{
    public function __construct()
    {
        parent::__construct('grapheme_str_split');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('grapheme_str_split() requires one or two arguments');
        }
        $string = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'grapheme_str_split',
            0,
            'string'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $length = 1;
        if (2 === $argc) {
            $length = VmMath::parseIntBuiltinArg(
                $frame->calledArgs[1],
                'grapheme_str_split',
                2,
                'length'
            );
        }
        $parts = VmGrapheme::strSplit($string, $length);
        if (false === $parts) {
            BuiltinExecute::writeReturn($frame, static function (Variable $ret): void {
                $ret->bool(false);
            });

            return;
        }
        $out = new HashTable();
        foreach ($parts as $part) {
            $stored = new Variable();
            $stored->string($part);
            $out->append($stored);
        }
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($out): void {
            $ret->array($out);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('grapheme_str_split() requires one or two arguments');
        }
        $folded = JitGrapheme::tryStrSplitFold($context, $args);
        if (null !== $folded) {
            return $folded;
        }
        JitStringBuiltinArg::lower($context, $args[0], 'grapheme_str_split', 0, 'string');

        throw new \LogicException(
            'grapheme_str_split() JIT runtime lowering is deferred; use VM or compile-time literals (#5958)'
        );
    }
}
