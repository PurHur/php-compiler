<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\Variable;

/**
 * By-reference parameter indices for VM builtins (issue #3578).
 *
 * @return list<int>
 */
final class BuiltinByRefParams
{
    public static function forFunction(string $name): array
    {
        switch (strtolower($name)) {
            case 'array_multisort':
            case 'array_push':
            case 'array_pop':
            case 'array_shift':
            case 'array_unshift':
            case 'array_splice':
            case 'end':
            case 'next':
            case 'prev':
            case 'reset':
            case 'array_walk':
            case 'array_walk_recursive':
            case 'asort':
            case 'arsort':
            case 'ksort':
            case 'krsort':
            case 'natcasesort':
            case 'natsort':
            case 'rsort':
            case 'shuffle':
            case 'sort':
            case 'uasort':
            case 'uksort':
            case 'usort':
                return [0];
            case 'modf':
                return [1];
            case 'frexp':
                return [1];
            case 'parse_str':
                return [1];
            case 'xml_parse_into_struct':
                return [2, 3];
            case 'dns_get_mx':
            case 'getmxrr':
                return [1, 2];
            case 'stream_socket_client':
                return [1, 2];
            case 'stream_socket_accept':
                return [2];
            case 'stream_socket_server':
                return [1, 2];
            case 'fsockopen':
            case 'pfsockopen':
                return [2, 3];
            case 'settype':
                return [0];
            case 'similar_text':
                return [2];
            case 'preg_match':
            case 'preg_match_all':
                return [2];
            case 'mb_ereg':
            case 'mb_eregi':
                return [2];
            case 'preg_replace':
                return [4];
            case 'str_replace':
            case 'str_ireplace':
                return [3];
            case 'headers_sent':
                return [0, 1];
            case 'flock':
                return [2];
            case 'getopt':
                return [2];
            case 'is_callable':
                return [2];
            case 'openssl_random_pseudo_bytes':
            case 'openssl_sign':
            case 'openssl_public_encrypt':
            case 'openssl_private_decrypt':
            case 'openssl_private_encrypt':
            case 'openssl_public_decrypt':
            case 'openssl_open':
            case 'openssl_pkey_export':
            case 'openssl_pkcs12_export':
                return [1];
            case 'openssl_pkcs12_read':
                return [1];
            case 'openssl_seal':
                return [1, 2, 5];
            case 'stream_context_set_options':
            case 'stream_context_set_option':
            case 'stream_context_set_params':
                return [0];
            case 'exec':
                return [1, 2];
            case 'passthru':
            case 'system':
                return [1];
            case 'proc_open':
                return [2];
            case 'grapheme_extract':
                return [4];
            case 'sodium_crypto_secretstream_xchacha20poly1305_push':
            case 'sodium_crypto_secretstream_xchacha20poly1305_pull':
            case 'sodium_crypto_secretstream_xchacha20poly1305_rekey':
                return [0];
            case 'uuid_generate':
                return [0];
        }

        return [];
    }

    /** First argument index passed by reference for variadic tail (issue #3190). */
    public static function variadicByRefFromIndex(string $name): ?int
    {
        if (\in_array(strtolower($name), ['sscanf', 'vfscanf', 'fscanf'], true)) {
            return 2;
        }
        if ('array_multisort' === strtolower($name)) {
            return 0;
        }

        return null;
    }

    /**
     * Whether $argIndex is ZEND_SEND_REF for $name.
     * array_multisort() only passes array operands by reference, not SORT_* flags (#9481, ext/standard/array.c).
     */
    public static function isByRefArg(string $name, int $argIndex, ?Variable $runtimeValue = null): bool
    {
        $lc = strtolower($name);
        if ('array_multisort' === $lc) {
            if (null === $runtimeValue) {
                return false;
            }

            return Variable::TYPE_ARRAY === $runtimeValue->resolveIndirect()->type;
        }
        if (\in_array($argIndex, self::forFunction($lc), true)) {
            return true;
        }
        $variadicFrom = self::variadicByRefFromIndex($lc);
        if (null === $variadicFrom || $argIndex < $variadicFrom) {
            return false;
        }

        return true;
    }
}
