<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\VM\Context;

/**
 * php-src @not-serializable intl objects (issue #23092):
 * - IntlDateFormatter — ext/intl/dateformat/dateformat.stub.php
 * - NumberFormatter — ext/intl/formatter/formatter.stub.php
 * - Collator — ext/intl/collator/collator.stub.php
 * - MessageFormatter — ext/intl/msgformat/msgformat.stub.php
 * - ResourceBundle — ext/intl/resourcebundle/resourcebundle.stub.php
 * - IntlCalendar / IntlGregorianCalendar (+ subclasses) — ext/intl/calendar/calendar.stub.php
 */
final class IntlSerializeDeny
{
    /** @var list<string> */
    private const DENIED_LC = [
        VmIntlDateFormatter::CLASS_LC,
        VmNumberFormatter::CLASS_LC,
        VmCollator::CLASS_LC,
        VmMessageFormatter::CLASS_LC,
        VmResourceBundle::CLASS_LC,
        VmIntlCalendar::CLASS_LC,
        VmIntlCalendar::GREGORIAN_CLASS_LC,
    ];

    public static function rejectSerialization(string $className, ?Context $ctx = null): void
    {
        if (self::isDenied($className, $ctx)) {
            throw new \Exception("Serialization of '".self::displayName($className)."' is not allowed");
        }
    }

    public static function rejectUnserialization(string $className, ?Context $ctx = null): void
    {
        if (self::isDenied($className, $ctx)) {
            throw new \Exception("Unserialization of '".self::displayName($className)."' is not allowed");
        }
    }

    private static function isDenied(string $className, ?Context $ctx): bool
    {
        $lc = strtolower(ltrim($className, '\\'));
        if (\in_array($lc, self::DENIED_LC, true)) {
            return true;
        }
        if (null === $ctx) {
            return false;
        }
        // IntlGregorianCalendar and user subclasses inherit deny from IntlCalendar
        // (php-src calendar.stub.php @not-serializable on IntlCalendar).
        $entry = $ctx->classes[$lc] ?? null;
        while (null !== $entry) {
            $nameLc = strtolower($entry->name);
            if (VmIntlCalendar::CLASS_LC === $nameLc
                || VmIntlCalendar::GREGORIAN_CLASS_LC === $nameLc) {
                return true;
            }
            $parentLc = $entry->parentLc;
            if (null === $parentLc || '' === $parentLc) {
                break;
            }
            if (VmIntlCalendar::CLASS_LC === $parentLc
                || VmIntlCalendar::GREGORIAN_CLASS_LC === $parentLc) {
                return true;
            }
            $entry = $ctx->classes[$parentLc] ?? null;
        }

        return false;
    }

    private static function displayName(string $className): string
    {
        return ltrim($className, '\\');
    }
}
