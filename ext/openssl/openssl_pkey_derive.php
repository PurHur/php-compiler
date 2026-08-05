<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * openssl_pkey_derive() — EVP_PKEY_derive (php-src ext/openssl/openssl.c / pkey.c; issue #15428, #26689, #27685).
 *
 * Args are untyped zvals in php-src (`zend_parse_parameters(..., "zz|l")`); invalid scalars soft-fail
 * to false via php_openssl_pkey_from_zval — not TypeError.
 *
 * Reflection / named args: Zend stub `public_key`, `private_key`, `int $key_length = 0`: `string|false`
 * (absent from php-types InternalArgInfo — see BuiltinParamNames / BuiltinInternalArgInfo).
 */
final class openssl_pkey_derive extends Internal
{
    public function __construct()
    {
        parent::__construct('openssl_pkey_derive');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(
                'openssl_pkey_derive() expects 2 or 3 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        // php-src loads private_key first, then peer public_key (order affects ValueError vs false).
        $privatePem = VmOpenssl::tryPkeyPemFromDeriveZval($frame->calledArgs[1], false);
        if (null === $privatePem) {
            $frame->returnVar->bool(false);

            return;
        }
        $publicPem = VmOpenssl::tryPkeyPemFromDeriveZval($frame->calledArgs[0], true);
        if (null === $publicPem) {
            $frame->returnVar->bool(false);

            return;
        }

        $keyLength = 0;
        if (3 === $argc) {
            $keyLength = self::coerceKeyLengthArg($frame->calledArgs[2], 'openssl_pkey_derive', 2, 'key_length');
        }

        $shared = VmOpenssl::pkeyDerive($publicPem, $privatePem, $keyLength, $frame);
        if (false === $shared) {
            $frame->returnVar->bool(false);

            return;
        }

        $frame->returnVar->string($shared);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'openssl_pkey_derive() is not implemented for JIT in this compiler build (issue #15428)'
        );
    }

    private static function coerceKeyLengthArg(\PHPCompiler\VM\Variable $var, string $function, int $argIndex, string $paramName): int
    {
        $var = $var->resolveIndirect();
        if (\PHPCompiler\VM\Variable::TYPE_INTEGER === $var->type) {
            return $var->toInt();
        }

        throw new \TypeError(\sprintf(
            '%s(): Argument #%d ($%s) must be of type int, %s given',
            $function,
            $argIndex + 1,
            $paramName,
            match ($var->type) {
                \PHPCompiler\VM\Variable::TYPE_NULL => 'null',
                \PHPCompiler\VM\Variable::TYPE_BOOLEAN => 'bool',
                \PHPCompiler\VM\Variable::TYPE_FLOAT => 'float',
                \PHPCompiler\VM\Variable::TYPE_STRING => 'string',
                \PHPCompiler\VM\Variable::TYPE_ARRAY => 'array',
                \PHPCompiler\VM\Variable::TYPE_OBJECT => $var->toObject()->class->name,
                default => 'mixed',
            }
        ));
    }
}
