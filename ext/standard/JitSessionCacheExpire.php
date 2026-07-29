<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\SessionStorageGlobals;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for session_cache_expire() (issue #14613). */
final class JitSessionCacheExpire
{
    private const VALUE_ERROR = 'session_cache_expire(): Argument #1 ($value) must be greater than or equal to 0';

    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        SessionStorageGlobals::ensureGlobals($context);

        $i64 = $context->getTypeFromString('int64');
        $global = SessionStorageGlobals::$cacheExpireGlobal;
        if (null === $global) {
            throw new \LogicException('session_cache_expire() JIT global missing in this compiler build');
        }

        if (0 === \count($args)) {
            return $context->builder->load($global);
        }

        $minutes = JitIntdiv::lowerIntBuiltinArg(
            $context,
            $args[0],
            'session_cache_expire',
            1,
            'value'
        );
        self::emitPositiveGuard($context, $minutes);
        $context->builder->store($minutes, $global);

        return $context->builder->load($global);
    }

    private static function emitPositiveGuard(Context $context, Value $minutes): void
    {
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $invalid = $context->builder->icmp(Builder::INT_SLT, $minutes, $zero);
        $okBlock = BasicBlockHelper::append($context, 'sess_cache_expire_ok');
        $errBlock = BasicBlockHelper::append($context, 'sess_cache_expire_err');
        $context->builder->branchIf($invalid, $errBlock, $okBlock);
        $context->builder->positionAtEnd($errBlock);
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitValueError($context, self::VALUE_ERROR);
        $context->builder->call($context->lookupFunction('abort'));
        $context->builder->positionAtEnd($okBlock);
    }
}
