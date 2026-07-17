<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\StringUcwords;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
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
        $string = VmString::trimFamilyStringArgForFrame($frame, 0, 'ucwords', 0, 'string');
        $separators = VmString::TRIM_DEFAULT;
        if (2 === $argc) {
            $separators = VmString::trimFamilyStringArgForFrame($frame, 1, 'ucwords', 1, 'separators');
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
        StringUcwords::ensureLinked($context);
        $str = self::jitStringArg($context, $args[0], 0, 'string');
        if (1 === $argc) {
            return $context->builder->call(
                $context->lookupFunction('__string__ucwords'),
                $str
            );
        }

        return $context->builder->call(
            $context->lookupFunction('__string__ucwords_ex'),
            $str,
            self::jitStringArg($context, $args[1], 1, 'separators')
        );
    }

    /** Soft-null — coerce+deprecate on forward profile (#19998, ext/standard/string.c). */
    private static function jitStringArg(
        Context $context,
        JITVariable $arg,
        int $argIndex,
        string $paramName
    ): Value {
        if ($context->callerStrictTypes) {
            return JitStringBuiltinArg::lowerStrictOrCoercible(
                $context,
                $arg,
                'ucwords',
                $argIndex,
                $paramName
            );
        }

        return JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $arg,
            'ucwords',
            $argIndex,
            $paramName
        );
    }
}
