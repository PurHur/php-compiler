<?php

declare(strict_types=1);

namespace PHPCompiler\ext\filter;

use PHPCompiler\VM\Variable;

/**
 * FILTER_* ids and name map (php-src ext/filter/filter_private.h, filter_arginfo.h; #5839, #13046).
 *
 * Full validator parity tracked in #4403, #4742, #5796, #5199.
 */
final class FilterConstants
{
    /** @var array<string, int> lowercase filter name => id */
    public const NAME_TO_ID = [
        'validate_int' => VmFilter::FILTER_VALIDATE_INT,
        'validate_boolean' => VmFilter::FILTER_VALIDATE_BOOLEAN,
        'validate_float' => VmFilter::FILTER_VALIDATE_FLOAT,
        'validate_regexp' => VmFilter::FILTER_VALIDATE_REGEXP,
        'validate_url' => VmFilter::FILTER_VALIDATE_URL,
        'validate_email' => VmFilter::FILTER_VALIDATE_EMAIL,
        'validate_ip' => VmFilter::FILTER_VALIDATE_IP,
    ];

    /**
     * Extension constants registered into the filter bucket (php-src register_filter_symbols).
     *
     * @var array<string, int>
     */
    public const REGISTERED = [
        'INPUT_POST' => VmFilter::INPUT_POST,
        'INPUT_GET' => VmFilter::INPUT_GET,
        'INPUT_COOKIE' => VmFilter::INPUT_COOKIE,
        'INPUT_ENV' => VmFilter::INPUT_ENV,
        'INPUT_SERVER' => VmFilter::INPUT_SERVER,
        'INPUT_SESSION' => VmFilter::INPUT_SESSION,
        'FILTER_FLAG_NONE' => VmFilter::FILTER_FLAG_NONE,
        'FILTER_REQUIRE_SCALAR' => VmFilter::FILTER_REQUIRE_SCALAR,
        'FILTER_REQUIRE_ARRAY' => VmFilter::FILTER_REQUIRE_ARRAY,
        'FILTER_FORCE_ARRAY' => VmFilter::FILTER_FORCE_ARRAY,
        'FILTER_NULL_ON_FAILURE' => VmFilter::FILTER_NULL_ON_FAILURE,
        'FILTER_THROW_ON_FAILURE' => VmFilter::FILTER_THROW_ON_FAILURE,
        'FILTER_VALIDATE_INT' => VmFilter::FILTER_VALIDATE_INT,
        'FILTER_VALIDATE_BOOLEAN' => VmFilter::FILTER_VALIDATE_BOOLEAN,
        'FILTER_VALIDATE_BOOL' => VmFilter::FILTER_VALIDATE_BOOLEAN,
        'FILTER_VALIDATE_FLOAT' => VmFilter::FILTER_VALIDATE_FLOAT,
        'FILTER_VALIDATE_REGEXP' => VmFilter::FILTER_VALIDATE_REGEXP,
        'FILTER_VALIDATE_DOMAIN' => VmFilter::FILTER_VALIDATE_DOMAIN,
        'FILTER_VALIDATE_URL' => VmFilter::FILTER_VALIDATE_URL,
        'FILTER_VALIDATE_EMAIL' => VmFilter::FILTER_VALIDATE_EMAIL,
        'FILTER_VALIDATE_IP' => VmFilter::FILTER_VALIDATE_IP,
        'FILTER_VALIDATE_MAC' => VmFilter::FILTER_VALIDATE_MAC,
        'FILTER_DEFAULT' => VmFilter::FILTER_DEFAULT,
        'FILTER_UNSAFE_RAW' => VmFilter::FILTER_UNSAFE_RAW,
        'FILTER_SANITIZE_STRING' => VmFilter::FILTER_SANITIZE_STRING,
        'FILTER_SANITIZE_STRIPPED' => VmFilter::FILTER_SANITIZE_STRING,
        'FILTER_SANITIZE_ENCODED' => VmFilter::FILTER_SANITIZE_ENCODED,
        'FILTER_SANITIZE_SPECIAL_CHARS' => VmFilter::FILTER_SANITIZE_SPECIAL_CHARS,
        'FILTER_SANITIZE_FULL_SPECIAL_CHARS' => VmFilter::FILTER_SANITIZE_FULL_SPECIAL_CHARS,
        'FILTER_SANITIZE_EMAIL' => VmFilter::FILTER_SANITIZE_EMAIL,
        'FILTER_SANITIZE_URL' => VmFilter::FILTER_SANITIZE_URL,
        'FILTER_SANITIZE_NUMBER_INT' => VmFilter::FILTER_SANITIZE_NUMBER_INT,
        'FILTER_SANITIZE_NUMBER_FLOAT' => VmFilter::FILTER_SANITIZE_NUMBER_FLOAT,
        'FILTER_SANITIZE_ADD_SLASHES' => VmFilter::FILTER_SANITIZE_ADD_SLASHES,
        'FILTER_CALLBACK' => VmFilter::FILTER_CALLBACK,
        'FILTER_FLAG_ALLOW_OCTAL' => VmFilter::FILTER_FLAG_ALLOW_OCTAL,
        'FILTER_FLAG_ALLOW_HEX' => VmFilter::FILTER_FLAG_ALLOW_HEX,
        'FILTER_FLAG_STRIP_LOW' => VmFilter::FILTER_FLAG_STRIP_LOW,
        'FILTER_FLAG_STRIP_HIGH' => VmFilter::FILTER_FLAG_STRIP_HIGH,
        'FILTER_FLAG_STRIP_BACKTICK' => VmFilter::FILTER_FLAG_STRIP_BACKTICK,
        'FILTER_FLAG_ENCODE_LOW' => VmFilter::FILTER_FLAG_ENCODE_LOW,
        'FILTER_FLAG_ENCODE_HIGH' => VmFilter::FILTER_FLAG_ENCODE_HIGH,
        'FILTER_FLAG_ENCODE_AMP' => VmFilter::FILTER_FLAG_ENCODE_AMP,
        'FILTER_FLAG_NO_ENCODE_QUOTES' => VmFilter::FILTER_FLAG_NO_ENCODE_QUOTES,
        'FILTER_FLAG_EMPTY_STRING_NULL' => VmFilter::FILTER_FLAG_EMPTY_STRING_NULL,
        'FILTER_FLAG_ALLOW_FRACTION' => VmFilter::FILTER_FLAG_ALLOW_FRACTION,
        'FILTER_FLAG_ALLOW_THOUSAND' => VmFilter::FILTER_FLAG_ALLOW_THOUSAND,
        'FILTER_FLAG_ALLOW_SCIENTIFIC' => VmFilter::FILTER_FLAG_ALLOW_SCIENTIFIC,
        'FILTER_FLAG_PATH_REQUIRED' => VmFilter::FILTER_FLAG_PATH_REQUIRED,
        'FILTER_FLAG_QUERY_REQUIRED' => VmFilter::FILTER_FLAG_QUERY_REQUIRED,
        'FILTER_FLAG_IPV4' => VmFilter::FILTER_FLAG_IPV4,
        'FILTER_FLAG_IPV6' => VmFilter::FILTER_FLAG_IPV6,
        'FILTER_FLAG_NO_RES_RANGE' => VmFilter::FILTER_FLAG_NO_RES_RANGE,
        'FILTER_FLAG_NO_PRIV_RANGE' => VmFilter::FILTER_FLAG_NO_PRIV_RANGE,
        'FILTER_FLAG_GLOBAL_RANGE' => VmFilter::FILTER_FLAG_GLOBAL_RANGE,
        'FILTER_FLAG_HOSTNAME' => VmFilter::FILTER_FLAG_HOSTNAME,
        'FILTER_FLAG_EMAIL_UNICODE' => VmFilter::FILTER_FLAG_EMAIL_UNICODE,
    ];

    /** @return list<string> */
    public static function registeredNames(): array
    {
        return array_keys(self::REGISTERED);
    }

    public static function isRegisteredName(string $name): bool
    {
        return isset(self::REGISTERED[$name]);
    }

    /** @return list<string> */
    public static function supportedFilterNames(): array
    {
        return array_keys(self::NAME_TO_ID);
    }

    public static function idForName(string $name): ?int
    {
        $lc = strtolower($name);

        return self::NAME_TO_ID[$lc] ?? null;
    }

    public static function variableForName(string $name): ?Variable
    {
        $value = self::REGISTERED[$name] ?? null;
        if (null === $value) {
            return null;
        }
        $var = new Variable(Variable::TYPE_INTEGER);
        $var->int($value);

        return $var;
    }
}
