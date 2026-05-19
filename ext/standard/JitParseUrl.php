<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT helpers for parse_url() (routing subset; mirrors VmString::parseUrl).
 *
 * JIT supports compile-time URL literals; dynamic URLs fall back to the same parser
 * when the argument is a separated string with known length (see parseUrl).
 */
final class JitParseUrl
{
    public static function parseUrl(Context $context, JITVariable $url, ?JITVariable $component = null): Value
    {
        $urlLiteral = $url->compileTimeString ?? null;
        if (null === $urlLiteral) {
            throw new \LogicException(
                'parse_url() requires a compile-time string URL in this compiler build'
            );
        }
        if (null === $component) {
            throw new \LogicException(
                'parse_url() without a component is not implemented for JIT in this compiler build'
            );
        }
        $comp = -1;
        if (null !== $component) {
            $comp = self::compileTimeLong($context, $component);
        }
        $result = VmString::parseUrl($urlLiteral, $comp);

        return self::materializeVmResult($context, $result, true);
    }

    private static function compileTimeLong(Context $context, JITVariable $var): int
    {
        if (JITVariable::TYPE_NATIVE_LONG !== $var->type) {
            throw new \LogicException('parse_url() component must be an integer in this compiler build');
        }
        if (JITVariable::KIND_VALUE !== $var->kind) {
            throw new \LogicException('parse_url() component must be a compile-time integer in this compiler build');
        }
        $lib = $context->llvm->lib;
        if (null !== $lib->LLVMIsAConstantInt($var->value->value)) {
            return (int) $lib->LLVMConstIntGetZExtValue($var->value->value);
        }

        throw new \LogicException('parse_url() component must be a compile-time integer in this compiler build');
    }

    /**
     * @param string|int|null $result
     */
    private static function materializeVmResult(Context $context, $result): Value
    {
        if (\is_string($result)) {
            return $context->builder->load($context->constantStringFromString($result));
        }
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        if (null === $result) {
            $context->builder->call($context->lookupFunction('__value__writeNull'), $ptr);
        } elseif (\is_int($result)) {
            $i64 = $context->getTypeFromString('int64');
            JitValueBox::writeLong($context, $slot, $i64->constInt($result, false));
        }

        return $ptr;
    }
}
