<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\MbStrlenRuntime;
use PHPCompiler\JIT\Builtin\StringUtf8Runtime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT helpers for mb_strlen() (#158, #5695, #34625 runtime encoding).
 *
 * Compile-time fold + UTF-8 literal via `__compiler_utf8_strlen`; runtime encoding via NestedJIT
 * {@see MbStrlenJitHelper} (peer {@see JitMbSubstrCount} / {@see JitMbPreferredMimeName}).
 */
final class JitMbStrlen
{
    /**
     * @param list<JITVariable> $args
     */
    public static function invoke(Context $context, array $args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('mb_strlen() requires one or two arguments');
        }

        $folded = self::tryCompileTimeFold($context, $args, $argc);
        if (null !== $folded) {
            return $folded;
        }

        $str = JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'mb_strlen', 0, 'string');

        if (1 === $argc) {
            return self::utf8LengthFromPtr($context, $str);
        }

        if (JITVariable::TYPE_NULL === $args[1]->type || ($args[1]->isNullConstant ?? false)) {
            return self::utf8LengthFromPtr($context, $str);
        }

        $encodingLit = JitStringArg::compileTimeLiteral($args[1]);
        if (null !== $encodingLit) {
            $canonical = MbstringEncodingRegistry::resolve($encodingLit);
            if ('UTF-8' === $canonical) {
                return self::utf8LengthFromPtr($context, $str);
            }
            if ('ASCII' === $canonical || '8BIT' === $canonical || 'ISO-8859-1' === $canonical) {
                $offset = $context->structFieldIndex($str, 'length');

                return $context->builder->load(
                    $context->builder->structGep($str, $offset)
                );
            }
            if (null !== $canonical) {
                throw new \LogicException(
                    'mb_strlen() JIT only supports UTF-8, ASCII, 8BIT, or ISO-8859-1 encoding literals in this compiler build'
                );
            }
            // Invalid encoding name — NestedJIT throws catchable ValueError (#34625).
        }

        // Runtime encoding (TYPE_VALUE / non-literal TYPE_STRING) — NestedJIT (#34625).
        $enc = null !== $encodingLit
            ? $context->builder->load($context->constantStringFromString($encodingLit))
            : JitStringBuiltinArg::lower(
                $context,
                $args[1],
                'mb_strlen',
                1,
                'encoding'
            );

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        MbStrlenRuntime::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }
        BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_strlen_runtime');

        return $context->builder->call(
            MbStrlenRuntime::strlenHelper($context),
            $str,
            $enc
        );
    }

    public static function utf8LengthFromPtr(Context $context, Value $strPtr): Value
    {
        StringUtf8Runtime::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction('__compiler_utf8_strlen'),
            $strPtr
        );
    }

    public static function utf8Length(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_STRING === $arg->type && null !== ($arg->compileTimeString ?? null)) {
            return $context->constantFromInteger(
                VmString::utf8CharLength($arg->compileTimeString),
                'int64'
            );
        }

        $str = JitStringBuiltinArg::lowerTrimFamilyString($context, $arg, 'mb_strlen', 0, 'string');

        return self::utf8LengthFromPtr($context, $str);
    }

    /**
     * @param list<JITVariable> $args
     */
    private static function tryCompileTimeFold(Context $context, array $args, int $argc): ?Value
    {
        if (JITVariable::TYPE_STRING !== $args[0]->type || null === ($args[0]->compileTimeString ?? null)) {
            return null;
        }
        if (1 === $argc) {
            return $context->constantFromInteger(
                VmString::utf8CharLength($args[0]->compileTimeString),
                'int64'
            );
        }
        $encodingLit = JitStringArg::compileTimeLiteral($args[1]);
        if (null === $encodingLit) {
            if (JITVariable::TYPE_NULL === $args[1]->type || ($args[1]->isNullConstant ?? false)) {
                return $context->constantFromInteger(
                    VmString::utf8CharLength($args[0]->compileTimeString),
                    'int64'
                );
            }

            return null;
        }
        $canonical = MbstringEncodingRegistry::resolve($encodingLit);
        if (null === $canonical) {
            return null;
        }
        if ('UTF-8' === $canonical) {
            return $context->constantFromInteger(
                VmString::utf8CharLength($args[0]->compileTimeString),
                'int64'
            );
        }
        if ('ASCII' === $canonical || '8BIT' === $canonical || 'ISO-8859-1' === $canonical) {
            return $context->constantFromInteger(
                VmString::byteLength($args[0]->compileTimeString),
                'int64'
            );
        }

        return null;
    }
}
