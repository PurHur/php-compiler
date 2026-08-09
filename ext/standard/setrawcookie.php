<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\NamedOptionalCallArgs;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * setrawcookie() — emit Set-Cookie without URL-encoding the value (mirrors setcookie; issue #1368).
 *
 * VM uses ResponseContext only — no host Zend setrawcookie() delegation (bootstrap/M5; #5344 phase 3).
 * php-src: ext/standard/head.c — PHP_FUNCTION(setrawcookie) / Z_PARAM_STR $name
 * Null → E_DEPRECATED + empty name ValueError on 8.4 forward profile (#21233, re-#21003).
 * AOT densify pads omitted named slots with null — skip via isOmittedOptional (#24968 AOT).
 */
final class setrawcookie extends Internal
{
    public function __construct()
    {
        parent::__construct('setrawcookie');
    }

    public function execute(Frame $frame): void
    {
        $parsed = SetcookieOptions::parseArgs('setrawcookie', $frame->calledArgs, $frame);
        $ok = VmSetcookie::emit(
            $frame,
            'setrawcookie',
            SetcookieLine::build(
                $parsed['name'],
                $parsed['value'],
                $parsed['expires'],
                $parsed['path'],
                $parsed['domain'],
                $parsed['secure'],
                $parsed['httponly'],
                $parsed['samesite'],
                $parsed['partitioned']
            )
        );
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (
            isset($args[2])
            && !NamedOptionalCallArgs::isOmittedOptional($args[2])
            && JitSetcookieOptions::isOptionsArrayArg($args[2])
        ) {
            $valueArg = (isset($args[1]) && !NamedOptionalCallArgs::isOmittedOptional($args[1]))
                ? $args[1]
                : self::emptyStringArg($context);

            return JitSetcookieOptions::invoke($context, 'setrawcookie', $args[0], $valueArg, $args[2]);
        }
        if ($argc < 1 || $argc > 7) {
            throw new \LogicException('setrawcookie() accepts one to seven arguments');
        }
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $namePtr = JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'setrawcookie', 0, 'name');
        JitStringBuiltinArg::rejectEmpty(
            $context,
            $args[0],
            $namePtr,
            'setrawcookie(): Argument #1 ($name) must not be empty'
        );
        $valuePtr = $context->builder->load($context->constantStringFromString(''));
        if (isset($args[1]) && !NamedOptionalCallArgs::isOmittedOptional($args[1])) {
            $valuePtr = JitStringBuiltinArg::lower($context, $args[1], 'setrawcookie', 1, 'value');
        }
        $expiresI64 = $i64->constInt(0, false);
        if (isset($args[2]) && !NamedOptionalCallArgs::isOmittedOptional($args[2])) {
            if (JITVariable::TYPE_NULL === $args[2]->type || $args[2]->isNullConstant) {
                if ($context->callerStrictTypes) {
                    throw new \LogicException(
                        'setrawcookie(): Argument #3 ($expires_or_options) must be of type array|int, null given'
                    );
                }
                JitIntdiv::emitNullIntDeprecation($context, 'setrawcookie', 3, 'expires_or_options', 'array|int');
            } else {
                $expiresI64 = JitIntdiv::lowerIntBuiltinArg($context, $args[2], 'setrawcookie', 3, 'expires_or_options');
            }
        }
        $pathPtr = $context->builder->load($context->constantStringFromString(''));
        $hasPath = isset($args[3]) && !NamedOptionalCallArgs::isOmittedOptional($args[3]);
        if ($hasPath) {
            $pathPtr = JitStringBuiltinArg::lower($context, $args[3], 'setrawcookie', 3, 'path');
        }
        $domainPtr = $context->builder->load($context->constantStringFromString(''));
        if (isset($args[4]) && !NamedOptionalCallArgs::isOmittedOptional($args[4])) {
            $domainPtr = JitStringBuiltinArg::lower($context, $args[4], 'setrawcookie', 4, 'domain');
        }
        $secureI32 = $i32->constInt(0, false);
        if (isset($args[5]) && !NamedOptionalCallArgs::isOmittedOptional($args[5])) {
            $secureI32 = $context->builder->zExt(
                $this->jitBool($context, $args[5], 'setrawcookie() secure'),
                $i32
            );
        }
        $httponlyI32 = $i32->constInt(0, false);
        if (isset($args[6]) && !NamedOptionalCallArgs::isOmittedOptional($args[6])) {
            $httponlyI32 = $context->builder->zExt(
                $this->jitBool($context, $args[6], 'setrawcookie() httponly'),
                $i32
            );
        }
        $samesitePtr = $strPtr->constNull();
        $partitionedI32 = $i32->constInt(0, false);

        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            JitSetcookie::emitPending(
                $context,
                $namePtr,
                $valuePtr,
                $expiresI64,
                $pathPtr,
                $domainPtr,
                $secureI32,
                $httponlyI32,
                $samesitePtr,
                $partitionedI32
            );

            return $context->constantFromBool(true);
        }

        if (null === self::compileTimeArgs($args)) {
            throw new \LogicException(
                'setrawcookie() JIT requires compile-time constant arguments (name/value/path; expires 0) in this compiler build'
            );
        }
        JitSetcookie::emitPrintf(
            $context,
            $namePtr,
            $valuePtr,
            $hasPath ? $pathPtr : null
        );

        return $context->constantFromBool(true);
    }

    private static function emptyStringArg(Context $context): JITVariable
    {
        $str = new JITVariable(
            $context,
            JITVariable::TYPE_STRING,
            JITVariable::KIND_VALUE,
            $context->builder->load($context->constantStringFromString(''))
        );
        $str->compileTimeString = '';

        return $str;
    }

    /**
     * @param JITVariable[] $args
     *
     * @return array{name: string, value: string, expires: int, path: string, domain: string, secure: bool, httponly: bool}|null
     */
    private static function compileTimeArgs(array $args): ?array
    {
        $name = JitStringArg::compileTimeLiteral($args[0]);
        if (null === $name) {
            return null;
        }
        $value = '';
        if (isset($args[1]) && !NamedOptionalCallArgs::isOmittedOptional($args[1])) {
            $v = JitStringArg::compileTimeLiteral($args[1]);
            if (null === $v) {
                return null;
            }
            $value = $v;
        }
        $expires = 0;
        if (isset($args[2]) && !NamedOptionalCallArgs::isOmittedOptional($args[2])) {
            if (JITVariable::TYPE_NATIVE_LONG !== $args[2]->type) {
                return null;
            }
            $e = self::nativeLongLiteral($args[2]);
            $expires = null === $e ? 0 : $e;
        }
        $path = '';
        if (isset($args[3]) && !NamedOptionalCallArgs::isOmittedOptional($args[3])) {
            $p = JitStringArg::compileTimeLiteral($args[3]);
            if (null === $p) {
                return null;
            }
            $path = $p;
        }
        if (isset($args[4]) && !NamedOptionalCallArgs::isOmittedOptional($args[4])) {
            return null;
        }
        $secure = false;
        $httponly = false;

        return [
            'name' => $name,
            'value' => $value,
            'expires' => $expires,
            'path' => $path,
            'domain' => '',
            'secure' => $secure,
            'httponly' => $httponly,
        ];
    }

    private static function nativeLongLiteral(JITVariable $var): ?int
    {
        if (null !== $var->compileTimeString && is_numeric($var->compileTimeString)) {
            return (int) $var->compileTimeString;
        }

        return null;
    }

}
