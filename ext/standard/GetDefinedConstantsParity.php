<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Zend 8.2 reference-profile gaps for get_defined_constants(true) module buckets (#17896, basic_functions.c).
 */
final class GetDefinedConstantsParity
{
    /** Registered for compile but absent from Zend categorized standard bucket. */
    private const STANDARD_BUCKET_EXCLUDE = [
        'ARRAY_PAD_BOTH' => true,
        'ARRAY_PAD_LEFT' => true,
        'ARRAY_PAD_RIGHT' => true,
        'CREDITS_WEB' => true,
        'DEBUG_BACKTRACE_IGNORE_STATIC_ARGS' => true,
        // IMAGETYPE_HEIF: withheld from registration on PROFILE<8.5 (#22787); when advertised it belongs in standard.
        'LOG_FTP' => true,
        'PHP_ROUND_AWAY_FROM_ZERO' => true,
        'PHP_ROUND_CEILING' => true,
        'PHP_ROUND_FLOOR' => true,
        'PHP_ROUND_TOWARD_ZERO' => true,
        'STREAM_FILTER' => true,
        'STREAM_IPROTO_IP' => true,
        'STREAM_LOCK' => true,
        'STREAM_META_SEEKABLE' => true,
        'SUNFUNCS_RET_DOUBLE' => true,
        'SUNFUNCS_RET_STRING' => true,
        'SUNFUNCS_RET_TIMESTAMP' => true,
    ];

    /** @return list<string> */
    public static function sunfuncsConstantNames(): array
    {
        return [
            'SUNFUNCS_RET_TIMESTAMP',
            'SUNFUNCS_RET_STRING',
            'SUNFUNCS_RET_DOUBLE',
        ];
    }

    public static function isStandardBucketExcluded(string $name): bool
    {
        return isset(self::STANDARD_BUCKET_EXCLUDE[$name]);
    }

    /** @return list<string> */
    public static function standardBucketExcludeNames(): array
    {
        return array_keys(self::STANDARD_BUCKET_EXCLUDE);
    }

    /**
     * @return array<string, int>
     */
    public static function standardIntConstants(): array
    {
        return [
            'INI_USER' => 1,
            'INI_PERDIR' => 2,
            'INI_SYSTEM' => 4,
            'INI_ALL' => 7,
            'CHAR_MAX' => 127,
            'STREAM_FILTER_ALL' => 3,
            'STREAM_CRYPTO_METHOD_ANY_CLIENT' => 127,
            'STREAM_CRYPTO_METHOD_SSLv2_CLIENT' => 3,
            'STREAM_CRYPTO_METHOD_SSLv3_CLIENT' => 5,
            'STREAM_CRYPTO_METHOD_SSLv23_CLIENT' => 57,
            'STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT' => 9,
            'STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT' => 17,
            'STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT' => 33,
            'STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT' => 65,
            'STREAM_CRYPTO_METHOD_ANY_SERVER' => 126,
            'STREAM_CRYPTO_METHOD_SSLv2_SERVER' => 2,
            'STREAM_CRYPTO_METHOD_SSLv3_SERVER' => 4,
            'STREAM_CRYPTO_METHOD_SSLv23_SERVER' => 120,
            'STREAM_CRYPTO_METHOD_TLSv1_0_SERVER' => 8,
            'STREAM_CRYPTO_METHOD_TLSv1_1_SERVER' => 16,
            'STREAM_CRYPTO_METHOD_TLSv1_2_SERVER' => 32,
            'STREAM_CRYPTO_METHOD_TLSv1_3_SERVER' => 64,
            'STREAM_CRYPTO_PROTO_SSLv3' => 4,
            'STREAM_CRYPTO_PROTO_TLSv1_0' => 8,
            'STREAM_CRYPTO_PROTO_TLSv1_1' => 16,
            'STREAM_CRYPTO_PROTO_TLSv1_2' => 32,
            'STREAM_CRYPTO_PROTO_TLSv1_3' => 64,
            'STREAM_SHUT_RD' => 0,
            'STREAM_SHUT_WR' => 1,
            'STREAM_SHUT_RDWR' => 2,
            'STREAM_PF_INET6' => 10,
            'STREAM_IPPROTO_TCP' => 6,
            'STREAM_IPPROTO_UDP' => 17,
            'STREAM_IPPROTO_ICMP' => 1,
            'STREAM_IPPROTO_RAW' => 255,
            'STREAM_SOCK_RAW' => 3,
            'STREAM_SOCK_SEQPACKET' => 5,
            'STREAM_SOCK_RDM' => 4,
            'STREAM_PEEK' => 2,
            'STREAM_OOB' => 1,
            'FILE_TEXT' => 0,
            'FILE_BINARY' => 0,
            'PSFS_ERR_FATAL' => 0,
            'PASSWORD_BCRYPT_DEFAULT_COST' => VmPassword::bcryptDefaultCost(),
            'PASSWORD_ARGON2_DEFAULT_MEMORY_COST' => 65536,
            'PASSWORD_ARGON2_DEFAULT_TIME_COST' => 4,
            'PASSWORD_ARGON2_DEFAULT_THREADS' => 1,
            'ERA' => 131116,
            'ERA_YEAR' => 131117,
            'ERA_D_T_FMT' => 131120,
            'ERA_D_FMT' => 131118,
            'ERA_T_FMT' => 131121,
            'ALT_DIGITS' => 131119,
            'INT_CURR_SYMBOL' => 262144,
            'CURRENCY_SYMBOL' => 262145,
            'POSITIVE_SIGN' => 262149,
            'NEGATIVE_SIGN' => 262150,
            'INT_FRAC_DIGITS' => 262151,
            'FRAC_DIGITS' => 262152,
            'P_CS_PRECEDES' => 262153,
            'P_SEP_BY_SPACE' => 262154,
            'N_CS_PRECEDES' => 262155,
            'N_SEP_BY_SPACE' => 262156,
            'P_SIGN_POSN' => 262157,
            'N_SIGN_POSN' => 262158,
            'CRYPT_SALT_LENGTH' => 123,
            'CRYPT_STD_DES' => VmPassword::CRYPT_STD_DES,
            'CRYPT_EXT_DES' => VmPassword::CRYPT_EXT_DES,
            'CRYPT_MD5' => VmPassword::CRYPT_MD5,
            'CRYPT_BLOWFISH' => VmPassword::CRYPT_BLOWFISH,
            'CRYPT_SHA256' => VmPassword::CRYPT_SHA256,
            'CRYPT_SHA512' => VmPassword::CRYPT_SHA512,
            'STREAM_USE_PATH' => 1,
            'STREAM_IGNORE_URL' => 2,
            'STREAM_MUST_SEEK' => 16,
            'STREAM_URL_STAT_LINK' => 1,
            'STREAM_URL_STAT_QUIET' => 2,
            'STREAM_MKDIR_RECURSIVE' => 1,
            'STREAM_IS_URL' => 1,
            'STREAM_OPTION_BLOCKING' => 1,
            'STREAM_OPTION_READ_TIMEOUT' => 4,
            'STREAM_OPTION_READ_BUFFER' => 2,
            'STREAM_OPTION_WRITE_BUFFER' => 3,
            'STREAM_BUFFER_NONE' => 0,
            'STREAM_BUFFER_LINE' => 1,
            'STREAM_BUFFER_FULL' => 2,
        ];
    }

    /**
     * @return array<string, float>
     */
    public static function standardFloatConstants(): array
    {
        return [
            'INF' => \INF,
            'NAN' => \NAN,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function standardStringConstants(): array
    {
        return [
            'PASSWORD_ARGON2_PROVIDER' => 'standard',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function dateStringConstants(): array
    {
        return [
            'DATE_ISO8601_EXPANDED' => 'X-m-d\TH:i:sP',
            'DATE_RFC3339_EXTENDED' => 'Y-m-d\TH:i:s.vP',
            'DATE_RSS' => 'D, d M Y H:i:s O',
        ];
    }

    /**
     * @return array<string, int>
     */
    public static function jsonIntConstants(): array
    {
        return [
            'JSON_ERROR_INVALID_PROPERTY_NAME' => 9,
            'JSON_ERROR_UTF16' => 10,
            'JSON_ERROR_NON_BACKED_ENUM' => 11,
        ];
    }
}
