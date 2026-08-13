<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering for session cookie params / cache_limiter / save_path (#30758).
 *
 * Zero-arg getters materialize {@see VmSession} state at compile time (peer
 * timezone_abbreviations_list). Positional setters with compile-time literals update
 * that same slot so a later getter in the unit sees the write (php-src session.c).
 *
 * php-src: ext/session/session.c — session_get/set_cookie_params / cache_limiter / save_path
 */
final class JitSessionCookieAndPath
{
    public static function invokeGetCookieParams(Context $context, JITVariable ...$args): Value
    {
        if (0 !== \count($args)) {
            throw new \ArgumentCountError(
                \sprintf('session_get_cookie_params() expects exactly 0 arguments, %d given', \count($args))
            );
        }
        $source = VmSession::cookieParamsHashTable();
        $htVar = HashTableHelper::variableFromVmHashTable($context, $source);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $ptr,
            $context->helper->loadValue($htVar)
        );

        return $ptr;
    }

    public static function invokeSetCookieParams(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (0 === $argc) {
            throw new \ArgumentCountError('session_set_cookie_params() expects at least 1 argument, 0 given');
        }
        if ($argc > 5) {
            throw new \ArgumentCountError(
                \sprintf('session_set_cookie_params() expects at most 5 arguments, %d given', $argc)
            );
        }
        if (JITVariable::TYPE_HASHTABLE === $args[0]->type || JITVariable::TYPE_VALUE === $args[0]->type) {
            throw new \LogicException(
                'session_set_cookie_params() array options require VM in this compiler build (issue #30758)'
            );
        }

        $lifetime = self::compileTimeLong($args[0], 'session_set_cookie_params', 1, 'lifetime_or_options');
        $path = $argc >= 2
            ? self::compileTimeString($args[1], 'session_set_cookie_params', 2, 'path')
            : '/';
        $domain = $argc >= 3
            ? self::compileTimeString($args[2], 'session_set_cookie_params', 3, 'domain')
            : '';
        $secure = $argc >= 4 ? self::compileTimeBool($args[3]) : false;
        $httponly = $argc >= 5 ? self::compileTimeBool($args[4]) : false;

        // Thin AOT: fold into VmSession so later getCookieParams materialize sees the write.
        // Skip SAPI headersSent of the *compiler* process (not the user binary) (#30758).
        VmSession::forceApplyCookieParams([
            'lifetime' => $lifetime,
            'path' => $path,
            'domain' => $domain,
            'secure' => $secure,
            'httponly' => $httponly,
            'samesite' => '',
        ]);

        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $slot, $context->getTypeFromString('int1')->constInt(1, false));

        return JitValueBox::pointer($context, $slot);
    }

    public static function invokeCacheLimiter(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc > 1) {
            throw new \ArgumentCountError(
                \sprintf('session_cache_limiter() expects at most 1 argument, %d given', $argc)
            );
        }
        if (0 === $argc || self::isNullArg($args[0] ?? null)) {
            return self::writeString($context, VmSession::getCacheLimiter());
        }
        $newLimiter = self::compileTimeString($args[0], 'session_cache_limiter', 1, 'value');
        $previous = VmSession::forceSetCacheLimiter($newLimiter);

        return self::writeString($context, $previous);
    }

    public static function invokeSavePath(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc > 1) {
            throw new \ArgumentCountError(
                \sprintf('session_save_path() expects at most 1 argument, %d given', $argc)
            );
        }
        if (0 === $argc || self::isNullArg($args[0] ?? null)) {
            return self::writeString($context, VmSession::getSavePath());
        }
        $newPath = self::compileTimeString($args[0], 'session_save_path', 1, 'path');
        $previous = VmSession::forceSetSavePath($newPath);

        return self::writeString($context, $previous);
    }

    private static function isNullArg(?JITVariable $arg): bool
    {
        return null !== $arg && (JITVariable::TYPE_NULL === $arg->type || ($arg->isNullConstant ?? false));
    }

    private static function writeString(Context $context, string $value): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $str = $context->builder->load($context->constantStringFromString($value));
        $context->builder->call($context->lookupFunction('__value__writeString'), $ptr, $str);

        return $ptr;
    }

    private static function compileTimeLong(
        JITVariable $arg,
        string $function,
        int $userIndex,
        string $param
    ): int {
        if (null !== $arg->compileTimeLong) {
            return (int) $arg->compileTimeLong;
        }
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type && null !== $arg->value) {
            // Constant int from CFG.
            $lit = JitStringBuiltinArg::compileTimeLiteral($arg);
            if (null !== $lit && \is_numeric($lit)) {
                return (int) $lit;
            }
        }
        throw new \LogicException(
            $function.'() requires compile-time '.$param.' in this compiler build (issue #30758)'
        );
    }

    private static function compileTimeString(
        JITVariable $arg,
        string $function,
        int $userIndex,
        string $param
    ): string {
        $lit = JitStringBuiltinArg::compileTimeLiteral($arg);
        if (null !== $lit) {
            return $lit;
        }
        if (null !== $arg->compileTimeString) {
            return $arg->compileTimeString;
        }
        throw new \LogicException(
            $function.'() requires compile-time '.$param.' in this compiler build (issue #30758)'
        );
    }

    private static function compileTimeBool(JITVariable $arg): bool
    {
        if (JITVariable::TYPE_NATIVE_BOOL === $arg->type) {
            if (null !== $arg->compileTimeLong) {
                return 0 !== (int) $arg->compileTimeLong;
            }
            if ($arg->isNullConstant ?? false) {
                return false;
            }
        }
        if (null !== $arg->compileTimeLong) {
            return 0 !== (int) $arg->compileTimeLong;
        }
        throw new \LogicException(
            'session_set_cookie_params() requires compile-time bool operands in this compiler build (issue #30758)'
        );
    }
}
