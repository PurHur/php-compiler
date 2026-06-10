<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\Variable as VMVariable;
use PHPLLVM\Value;

/**
 * mb_strlen() — UTF-8 character count (php-src ext/mbstring/mbstring.c; #158, #5695).
 *
 * Full mbstring parity (additional encodings, mb_substr, …) tracked in #4405, #3239.
 */
final class mb_strlen extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_strlen');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('mb_strlen() requires one or two arguments');
        }
        $str = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'mb_strlen',
            0,
            'string'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $encoding = 'UTF-8';
        if (2 === $argc) {
            $encoding = VmString::coerceStringBuiltinArg(
                $frame->calledArgs[1],
                'mb_strlen',
                1,
                'encoding'
            );
        }
        $frame->returnVar->int(self::lengthForEncoding($str, $encoding));
    }

    public function call(Context $context, Variable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('mb_strlen() requires one or two arguments');
        }
        if (1 === $argc && Variable::TYPE_STRING === $args[0]->type && null !== ($args[0]->compileTimeString ?? null)) {
            return $context->constantFromInteger(
                VmString::utf8CharLength($args[0]->compileTimeString),
                'int64'
            );
        }

        $str = JitStringBuiltinArg::lower($context, $args[0], 'mb_strlen', 0, 'string');

        if (1 === $argc) {
            return JitMbStrlen::utf8LengthFromPtr($context, $str);
        }
        if (Variable::TYPE_STRING !== $args[1]->type) {
            throw new \LogicException('mb_strlen() encoding must be a string in this compiler build');
        }
        $encoding = $args[1]->compileTimeString ?? null;
        if ('UTF-8' === $encoding) {
            return JitMbStrlen::utf8LengthFromPtr($context, $str);
        }
        if (null !== $encoding && 'ASCII' !== $encoding && '8BIT' !== $encoding) {
            throw new \LogicException(
                'mb_strlen() JIT only supports UTF-8, ASCII, or 8BIT encoding literals in this compiler build'
            );
        }

        $offset = $context->structFieldIndex($str, 'length');

        return $context->builder->load(
            $context->builder->structGep($str, $offset)
        );
    }

    private static function lengthForEncoding(string $str, string $encoding): int
    {
        if ('UTF-8' === $encoding) {
            return VmString::utf8CharLength($str);
        }
        if ('ASCII' === $encoding || '8BIT' === $encoding) {
            return VmString::byteLength($str);
        }

        throw new \LogicException(
            'mb_strlen() requires mbstring for encoding '.$encoding.' in this compiler build'
        );
    }
}
