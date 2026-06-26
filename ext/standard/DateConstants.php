<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * ext/date format-string constants (php-src ext/date/php_date.c php_date_register_constants).
 */
final class DateConstants
{
    public const DATE_ATOM = 'Y-m-d\TH:i:sP';

    public const DATE_COOKIE = 'l, d-M-Y H:i:s T';

    public const DATE_ISO8601 = 'Y-m-d\TH:i:sO';

    public const DATE_RFC822 = 'D, d M y H:i:s O';

    public const DATE_RFC850 = 'l, d-M-y H:i:s T';

    public const DATE_RFC1036 = 'D, d M y H:i:s O';

    public const DATE_RFC1123 = 'D, d M Y H:i:s O';

    public const DATE_RFC7231 = 'D, d M Y H:i:s \G\M\T';

    public const DATE_RFC2822 = 'D, d M Y H:i:s O';

    public const DATE_RFC3339 = 'Y-m-d\TH:i:sP';

    public const DATE_W3C = 'Y-m-d\TH:i:sP';

    /** Lowercase name => format string for VM\Context::constantFetch(). */
    public const CORE_STRING_BY_NAME = [
        'date_atom' => self::DATE_ATOM,
        'date_cookie' => self::DATE_COOKIE,
        'date_iso8601' => self::DATE_ISO8601,
        'date_rfc822' => self::DATE_RFC822,
        'date_rfc850' => self::DATE_RFC850,
        'date_rfc1036' => self::DATE_RFC1036,
        'date_rfc1123' => self::DATE_RFC1123,
        'date_rfc7231' => self::DATE_RFC7231,
        'date_rfc2822' => self::DATE_RFC2822,
        'date_rfc3339' => self::DATE_RFC3339,
        'date_w3c' => self::DATE_W3C,
    ];

    /**
     * @return array<string, string>
     */
    public static function registeredConstants(): array
    {
        return [
            'DATE_ATOM' => self::DATE_ATOM,
            'DATE_COOKIE' => self::DATE_COOKIE,
            'DATE_ISO8601' => self::DATE_ISO8601,
            'DATE_RFC822' => self::DATE_RFC822,
            'DATE_RFC850' => self::DATE_RFC850,
            'DATE_RFC1036' => self::DATE_RFC1036,
            'DATE_RFC1123' => self::DATE_RFC1123,
            'DATE_RFC7231' => self::DATE_RFC7231,
            'DATE_RFC2822' => self::DATE_RFC2822,
            'DATE_RFC3339' => self::DATE_RFC3339,
            'DATE_W3C' => self::DATE_W3C,
        ];
    }
}
