<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering guard for openssl_random_pseudo_bytes() length (ext/openssl/openssl.c; #18156). */
final class JitOpensslRandomPseudoBytes
{
    private const LENGTH_ERROR = 'openssl_random_pseudo_bytes(): Argument #1 ($length) must be greater than 0';

    public static function emitRuntimeLengthGuard(Context $context, Value $length): void
    {
        $i64 = $context->getTypeFromString('int64');
        $one = $i64->constInt(1, false);
        $invalid = $context->builder->icmp(Builder::INT_SLT, $length, $one);
        $okBlock = BasicBlockHelper::append($context, 'ossl_rand_len_ok');
        $errBlock = BasicBlockHelper::append($context, 'ossl_rand_len_err');
        $context->builder->branchIf($invalid, $errBlock, $okBlock);
        $context->builder->positionAtEnd($errBlock);
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitValueError($context, self::LENGTH_ERROR);
        $context->builder->call($context->lookupFunction('abort'));
        $context->builder->positionAtEnd($okBlock);
    }

    public static function emitEmptyCipherAlgoError(Context $context, string $message): void
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitValueError($context, $message);
        $context->builder->call($context->lookupFunction('abort'));
    }
}
