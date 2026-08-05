<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Compiler\SourceLocation;
use PHPCompiler\Frame;

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
        self::registerClassConstants($entry);
        // php-src ext/date/php_date.stub.php — interface method table (#22609).
        BuiltinClasses::registerBuiltinInterfaceMethods($entry, [
            'format',
            'getTimezone',
            'getOffset',
            'getTimestamp',
            'diff',
            '__wakeup',
            '__serialize',
            '__unserialize',
        ]);
        $ctx->classes[self::INTERFACE_LC] = $entry;
    }

    /**
     * php-src copies DateTimeInterface format constants onto DateTime / DateTimeImmutable
     * class entries (ext/date/php_date.c / php_date.stub.php) so defined() and
     * ReflectionClass::getConstants() see them on the concrete classes (#22271).
     */
    public static function registerClassConstants(ClassEntry $entry): void
    {
        foreach (self::FORMAT_CONSTANTS as $name => $format) {
            $const = new Variable(Variable::TYPE_STRING);
            $const->string($format);
            // Declared casing is uppercase (DateTimeInterface::ATOM); keys are case-sensitive (#25929).
            $canonical = strtoupper($name);
            $entry->constants[$canonical] = $const;
            $entry->constNames[$canonical] = $canonical;
        }
    }

    public static function isDateTimeInterfaceLc(string $ifaceLc): bool
    {
        return self::INTERFACE_LC === strtolower(ltrim($ifaceLc, '\\'));
    }

    public static function rejectsUserImplementationLc(string $ifaceLc): bool
    {
        return self::isDateTimeInterfaceLc($ifaceLc);
    }

    /**
     * php-src zend_check_implement_interface — user classes cannot implement DateTimeInterface (#18781).
     *
     * @param list<string> $interfaceLcs
     */
    public static function assertUserMayImplement(
        array $interfaceLcs,
        Frame $frame,
        ?SourceLocation $sourceLocation = null,
    ): void {
        foreach ($interfaceLcs as $ifaceLc) {
            if (self::rejectsUserImplementationLc($ifaceLc)) {
                self::throwRuntimeFatal($frame, $sourceLocation);
            }
        }
    }

    /**
     * @return never
     */
    private static function throwRuntimeFatal(Frame $frame, ?SourceLocation $sourceLocation): void
    {
        $file = '' !== $frame->scriptPath ? $frame->scriptPath : 'Standard input code';
        if (null !== $sourceLocation && '' !== $sourceLocation->filename) {
            $file = $sourceLocation->filename;
        }
        $line = null !== $sourceLocation && $sourceLocation->startLine > 0
            ? $sourceLocation->startLine
            : FatalSite::lineFromOpcodes($frame);
        throw new \LogicException(sprintf(
            'PHP Fatal error:  %s in %s on line %d',
            self::USER_IMPLEMENTATION_FORBIDDEN_MESSAGE,
            $file,
            max(1, $line),
        ));
    }
}
