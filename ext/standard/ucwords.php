<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\InternalStrictArg as JitInternalStrictArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPLLVM\Value;

/**
 * ucwords() for strings (subset of PHP; ASCII letters).
 *
 * php-src: ext/standard/string.c — php_ucwords() / php_ucwords_ex()
 */
final class ucwords extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('ucwords() requires one or two arguments');
        }
        InternalStrictArg::rejectNullString($frame->calledArgs[0], 'ucwords', 'string', 0);
        $string = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'ucwords',
            0,
            'string'
        );
        $separators = VmString::TRIM_DEFAULT;
        if (2 === $argc) {
            $separators = VmString::coerceStringBuiltinArg(
                $frame->calledArgs[1],
                'ucwords',
                1,
                'separators'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(VmString::asciiUcwordsEx($string, $separators));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('ucwords() requires one or two arguments');
        }
        JitInternalStrictArg::rejectNullString($context, $args[0], 'ucwords', 'string', 1);
        $str = JitStringBuiltinArg::lower($context, $args[0], 'ucwords', 0, 'string');
        if (1 === $argc) {
            return $context->builder->call(
                $context->lookupFunction('__string__ucwords'),
                $str
            );
        }

        return $context->builder->call(
            $context->lookupFunction('__string__ucwords_ex'),
            $str,
            JitStringBuiltinArg::lower($context, $args[1], 'ucwords', 1, 'separators')
        );
    }
}
