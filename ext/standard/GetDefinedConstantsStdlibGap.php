<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * ext/standard constants missing from get_defined_constants(true) buckets (#17896).
 *
 * php-src: ext/standard/basic_functions.c, streams.c, crypt_freesec.c, password.c, nl_langinfo.c
 */
final class GetDefinedConstantsStdlibGap
{
    /** @return array<string, int> */
    public static function intConstants(): array
    {
        return [
            'ALT_DIGITS' => 131119,
            'CHAR_MAX' => 127,
            'CRYPT_BLOWFISH' => 1,
            'CRYPT_EXT_DES' => 1,
            'CRYPT_MD5' => 1,
            'CRYPT_SALT_LENGTH' => 123,
            'CRYPT_SHA256' => 1,
            'CRYPT_SHA512' => 1,
            'CRYPT_STD_DES' => 1,
            'CURRENCY_SYMBOL' => 262145,
            'ERA' => 131116,
            'ERA_D_FMT' => 131118,
            'ERA_D_T_FMT' => 131120,
            'ERA_T_FMT' => 131121,
            'ERA_YEAR' => 131117,
            'FILE_BINARY' => 0,
            'FILE_TEXT' => 0,
            'FRAC_DIGITS' => 262152,
            'INI_ALL' => 7,
            'INI_PERDIR' => 2,
            'INI_SYSTEM' => 4,
            'INI_USER' => 1,
            'INT_CURR_SYMBOL' => 262144,
            'INT_FRAC_DIGITS' => 262151,
            'NEGATIVE_SIGN' => 262150,
            'N_CS_PRECEDES' => 262155,
            'N_SEP_BY_SPACE' => 262156,
            'N_SIGN_POSN' => 262158,
            'PASSWORD_ARGON2_DEFAULT_MEMORY_COST' => 65536,
            'PASSWORD_ARGON2_DEFAULT_THREADS' => 1,
            'PASSWORD_ARGON2_DEFAULT_TIME_COST' => 4,
            'PASSWORD_BCRYPT_DEFAULT_COST' => 10,
            'POSITIVE_SIGN' => 262149,
            'PSFS_ERR_FATAL' => 0,
            'P_CS_PRECEDES' => 262153,
            'P_SEP_BY_SPACE' => 262154,
            'P_SIGN_POSN' => 262157,
            'STREAM_BUFFER_FULL' => 2,
            'STREAM_BUFFER_LINE' => 1,
            'STREAM_BUFFER_NONE' => 0,
            'STREAM_CRYPTO_METHOD_ANY_CLIENT' => 127,
            'STREAM_CRYPTO_METHOD_ANY_SERVER' => 126,
            'STREAM_CRYPTO_METHOD_SSLv23_CLIENT' => 57,
            'STREAM_CRYPTO_METHOD_SSLv23_SERVER' => 120,
            'STREAM_CRYPTO_METHOD_SSLv2_CLIENT' => 3,
            'STREAM_CRYPTO_METHOD_SSLv2_SERVER' => 2,
            'STREAM_CRYPTO_METHOD_SSLv3_CLIENT' => 5,
            'STREAM_CRYPTO_METHOD_SSLv3_SERVER' => 4,
            'STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT' => 9,
            'STREAM_CRYPTO_METHOD_TLSv1_0_SERVER' => 8,
            'STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT' => 17,
            'STREAM_CRYPTO_METHOD_TLSv1_1_SERVER' => 16,
            'STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT' => 33,
            'STREAM_CRYPTO_METHOD_TLSv1_2_SERVER' => 32,
            'STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT' => 65,
            'STREAM_CRYPTO_METHOD_TLSv1_3_SERVER' => 64,
            'STREAM_CRYPTO_PROTO_SSLv3' => 4,
            'STREAM_CRYPTO_PROTO_TLSv1_0' => 8,
            'STREAM_CRYPTO_PROTO_TLSv1_1' => 16,
            'STREAM_CRYPTO_PROTO_TLSv1_2' => 32,
            'STREAM_CRYPTO_PROTO_TLSv1_3' => 64,
            'STREAM_FILTER_ALL' => 3,
            'STREAM_IGNORE_URL' => 2,
            'STREAM_IPPROTO_ICMP' => 1,
            'STREAM_IPPROTO_RAW' => 255,
            'STREAM_IPPROTO_TCP' => 6,
            'STREAM_IPPROTO_UDP' => 17,
            'STREAM_IS_URL' => 1,
            'STREAM_MKDIR_RECURSIVE' => 1,
            'STREAM_MUST_SEEK' => 16,
            'STREAM_OOB' => 1,
            'STREAM_OPTION_BLOCKING' => 1,
            'STREAM_OPTION_READ_BUFFER' => 2,
            'STREAM_OPTION_READ_TIMEOUT' => 4,
            'STREAM_OPTION_WRITE_BUFFER' => 3,
            'STREAM_PEEK' => 2,
            'STREAM_PF_INET6' => 10,
            'STREAM_SHUT_RD' => 0,
            'STREAM_SHUT_RDWR' => 2,
            'STREAM_SHUT_WR' => 1,
            'STREAM_SOCK_RAW' => 3,
            'STREAM_SOCK_RDM' => 4,
            'STREAM_SOCK_SEQPACKET' => 5,
            'STREAM_URL_STAT_LINK' => 1,
            'STREAM_URL_STAT_QUIET' => 2,
            'STREAM_USE_PATH' => 1,
        ];
    }

    /** @return array<string, string> */
    public static function stringConstants(): array
    {
        return [
            'PASSWORD_ARGON2_PROVIDER' => 'standard',
        ];
    }

    /** @return array<string, float> */
    public static function floatConstants(): array
    {
        return [
            'INF' => \INF,
            'NAN' => \NAN,
        ];
    }
}
