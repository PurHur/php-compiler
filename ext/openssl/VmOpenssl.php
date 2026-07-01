<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

use PHPCompiler\ext\standard\VmHash;
use PHPCompiler\Frame;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * OpenSSL VM helpers without host ext/openssl delegation (#6228, #7331).
 *
 * php-src: ext/openssl/openssl.c
 */
final class VmOpenssl
{
    /**
     * openssl_cipher_iv_length() — required IV length for a cipher.
     *
     * @return int|false
     */
    public static function cipher_iv_length(string $cipherAlgo, ?Frame $frame = null): int|false
    {
        $length = OpensslCipherRegistry::cipherIvLength($cipherAlgo);
        if (false === $length) {
            self::userWarning('openssl_cipher_iv_length(): Unknown cipher algorithm', $frame);
        }

        return $length;
    }

    /**
     * openssl_cipher_key_length() — required key length for a cipher.
     *
     * @return int|false
     */
    public static function cipher_key_length(string $cipherAlgo, ?Frame $frame = null): int|false
    {
        $length = OpensslCipherRegistry::cipherKeyLength($cipherAlgo);
        if (false === $length) {
            self::userWarning('openssl_cipher_key_length(): Unknown cipher algorithm', $frame);
        }

        return $length;
    }

    public static function cipherMethods(bool $aliases = false): HashTable
    {
        return self::stringListToHashTable(OpensslCipherRegistry::cipherMethods($aliases));
    }

    public static function mdMethods(bool $aliases = false): HashTable
    {
        return self::stringListToHashTable(OpensslCipherRegistry::mdMethods($aliases));
    }

    /**
     * openssl_digest() — one-shot digest (EVP_Digest parity via VmHashNative).
     *
     * @return string|false
     */
    public static function digest(string $data, string $method, bool $rawOutput = false, ?Frame $frame = null): string|false
    {
        if (!OpensslCipherRegistry::digestImplemented($method)) {
            self::userWarning('openssl_digest(): Unknown digest algorithm', $frame);

            return false;
        }
        try {
            return VmHash::hash(strtolower($method), $data, $rawOutput);
        } catch (\ValueError) {
            self::userWarning('openssl_digest(): Unknown digest algorithm', $frame);

            return false;
        }
    }

    public static function coerceBoolArg(Variable $var, string $function, int $argIndex, string $paramName): bool
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_BOOLEAN === $var->type) {
            return $var->toBool();
        }

        throw new \TypeError(sprintf(
            '%s(): Argument #%d ($%s) must be of type bool, %s given',
            $function,
            $argIndex + 1,
            $paramName,
            match ($var->type) {
                Variable::TYPE_NULL => 'null',
                Variable::TYPE_INTEGER => 'int',
                Variable::TYPE_FLOAT => 'float',
                Variable::TYPE_STRING => 'string',
                Variable::TYPE_ARRAY => 'array',
                Variable::TYPE_OBJECT => 'object',
                default => 'mixed',
            }
        ));
    }

    /** @param list<string> $items */
    private static function stringListToHashTable(array $items): HashTable
    {
        $ht = new HashTable();
        foreach ($items as $item) {
            $var = new Variable();
            $var->string($item);
            $ht->append($var);
        }

        return $ht;
    }

    private static function userWarning(string $message, ?Frame $frame): void
    {
        if (null === $frame?->vmContext) {
            trigger_error($message, E_USER_WARNING);

            return;
        }
        $frame->vmContext->errors->triggerError(
            $message,
            ErrorReporter::E_USER_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame
        );
    }
}
