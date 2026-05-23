<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT helpers for parse_url() (routing subset; mirrors VmString::parseUrl).
 */
final class JitParseUrl
{
    public static function parseUrl(Context $context, JITVariable $url, ?JITVariable $component = null): Value
    {
        if (null === $component) {
            throw new \LogicException(
                'parse_url() without a component is not implemented for JIT in this compiler build'
            );
        }
        $comp = self::compileTimeLong($context, $component);
        $urlLiteral = $url->compileTimeString ?? null;
        if (null !== $urlLiteral) {
            return self::materializeVmResult($context, VmString::parseUrl($urlLiteral, $comp));
        }

        $urlStr = JitStringArg::lower($context, $url, 'parse_url() URL');
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $i64 = $context->getTypeFromString('int64');
        $context->builder->call(
            $context->lookupFunction('__phpc_parse_url_component'),
            $urlStr,
            $i64->constInt($comp, false),
            $ptr
        );
        if (\in_array($comp, [\PHP_URL_SCHEME, \PHP_URL_HOST, \PHP_URL_PATH, \PHP_URL_QUERY, \PHP_URL_FRAGMENT], true)) {
            return $context->builder->call(
                $context->lookupFunction('__value__readString'),
                $ptr
            );
        }

        return $ptr;
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
