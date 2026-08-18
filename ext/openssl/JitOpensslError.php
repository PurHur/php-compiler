<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\OpensslSignRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for openssl_error_string() (#32336).
 *
 * Thin-standalone AOT has no FFI, so {@see VmOpensslErrorNative} cannot drain the queue.
 * Declare libcrypto {@code ERR_get_error} / {@code ERR_error_string_n} (already linked when
 * {@see OpensslSignRuntime::opensslEvRuntimeAvailable()}). php-src: ext/openssl/openssl.c
 * PHP_FUNCTION(openssl_error_string).
 */
final class JitOpensslError
{
    private static int $blockSerial = 0;

    public static function invoke(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);

        if (!OpensslSignRuntime::opensslEvRuntimeAvailable()) {
            JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));

            return $ptr;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        self::ensureDecls($context);
        BasicBlockHelper::restoreInsertBlock($context, $savedInsert);

        $i64 = $context->getTypeFromString('int64');
        $rawErr = $context->builder->call($context->lookupFunction('ERR_get_error'));
        $err = $rawErr->typeOf() === $i64
            ? $rawErr
            : $context->builder->zExt($rawErr, $i64);

        $id = (string) (++self::$blockSerial);
        $empty = BasicBlockHelper::append($context, 'ossl_err_empty_'.$id);
        $have = BasicBlockHelper::append($context, 'ossl_err_have_'.$id);
        $done = BasicBlockHelper::append($context, 'ossl_err_done_'.$id);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $err, $i64->constInt(0, false)),
            $empty,
            $have
        );

        $context->builder->positionAtEnd($empty);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));
        $context->builder->branch($done);

        $context->builder->positionAtEnd($have);
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $buf = BasicBlockHelper::entryAlloca($context, $i8->arrayType(256));
        $bufPtr = $context->builder->pointerCast($buf, $i8p);
        $context->builder->call(
            $context->lookupFunction('ERR_error_string_n'),
            $err,
            $bufPtr,
            $sizeT->constInt(256, false)
        );
        LibcExtern::ensureStrlenDecl($context);
        $len = $context->builder->call($context->lookupFunction('strlen'), $bufPtr);
        $lenI64 = $len->typeOf() === $i64
            ? $len
            : $context->builder->zExt($len, $i64);
        $str = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $lenI64,
            $bufPtr
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $str
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);

        return $ptr;
    }

    private static function ensureDecls(Context $context): void
    {
        $ctx = $context->context;
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $void = $ctx->voidType();

        /** @var array<string, array{0: mixed, 1: bool, 2: list<mixed>}> $specs */
        $specs = [
            // unsigned long ERR_get_error(void) — LP64 i64.
            'ERR_get_error' => [$i64, false, []],
            // void ERR_error_string_n(unsigned long e, char *buf, size_t len)
            'ERR_error_string_n' => [$void, false, [$i64, $i8p, $sizeT]],
        ];

        foreach ($specs as $name => [$ret, $vararg, $params]) {
            $existing = $context->module->getNamedFunction($name);
            if (null !== $existing) {
                $context->registerFunction($name, $existing);

                continue;
            }
            try {
                $context->lookupFunction($name);

                continue;
            } catch (\Throwable) {
            }
            $fn = $context->module->addFunction($name, $ctx->functionType($ret, $vararg, ...$params));
            $context->registerFunction($name, $fn);
        }
    }
}
