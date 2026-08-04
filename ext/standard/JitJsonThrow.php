<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\TryCatchHelper;
use PHPLLVM\Value;

/**
 * Compile-time JSON_THROW_ON_ERROR → runtime catchable JsonException (#27623).
 *
 * Folding invalid literals must not abort AOT with an uncaught host JsonException;
 * emit the same catchable throw the VM would raise inside user try/catch.
 *
 * php-src: ext/json/php_json.c — JSON_THROW_ON_ERROR
 */
final class JitJsonThrow
{
    /**
     * Emit a catchable JsonException and return a dummy Call ABI value.
     */
    public static function emitFromException(Context $context, \JsonException $e): Value
    {
        TryCatchHelper::emitCatchableClassError(
            $context,
            'JsonException',
            $e->getMessage(),
            null,
            '',
            0,
            (int) $e->getCode()
        );
        // emitCatchableClassError terminates the insert block; dummy for Internal::call ABI.
        $unreachable = BasicBlockHelper::append($context, 'json_throw_unreach');
        $context->builder->positionAtEnd($unreachable);

        return JitValueBox::pointer($context, JitValueBox::alloc($context));
    }
}
