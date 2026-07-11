<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Built-in DateTimeInterface and format constants (ext/date/php_date.c, issue #7141).
 */
final class DateTimeInterfaceSupport
{
    public const INTERFACE_NAME = 'DateTimeInterface';
    public const INTERFACE_LC = 'datetimeinterface';

    /** php-src ext/date/php_date.c — internal interface; user classes cannot implement. */
    public const USER_IMPLEMENTATION_FORBIDDEN_MESSAGE = "DateTimeInterface can't be implemented by user classes";

    /** @var array<string, string> constant name (lowercase) => format string (php-src ext/date/php_date.c) */
    private const FORMAT_CONSTANTS = [
        'atom' => 'Y-m-d\\TH:i:sP',
        'cookie' => 'l, d-M-Y H:i:s T',
        'iso8601' => 'Y-m-d\\TH:i:sO',
        'iso8601_expanded' => 'X-m-d\\TH:i:sP',
        'rfc822' => 'D, d M y H:i:s O',
        'rfc850' => 'l, d-M-y H:i:s T',
        'rfc1036' => 'D, d M y H:i:s O',
        'rfc1123' => 'D, d M Y H:i:s O',
        'rfc7231' => 'D, d M Y H:i:s \\G\\M\\T',
        'rfc2822' => 'D, d M Y H:i:s O',
        'rfc3339' => 'Y-m-d\\TH:i:sP',
        'rfc3339_extended' => 'Y-m-d\\TH:i:s.vP',
        'rss' => 'D, d M Y H:i:s O',
        'w3c' => 'Y-m-d\\TH:i:sP',
    ];

    public static function register(Context $ctx): void
    {
        $entry = new ClassEntry(self::INTERFACE_NAME);
        $entry->isInterface = true;
        foreach (self::FORMAT_CONSTANTS as $name => $format) {
            $const = new Variable(Variable::TYPE_STRING);
            $const->string($format);
            $entry->constants[$name] = $const;
            $entry->constNames[$name] = strtoupper($name);
        }
        $ctx->classes[self::INTERFACE_LC] = $entry;
    }

    public static function isDateTimeInterfaceLc(string $ifaceLc): bool
    {
        return self::INTERFACE_LC === strtolower(ltrim($ifaceLc, '\\'));
    }

    public static function rejectsUserImplementationLc(string $ifaceLc): bool
    {
        return self::isDateTimeInterfaceLc($ifaceLc);
    }
}
