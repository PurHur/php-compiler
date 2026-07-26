<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\VM\Context;

/**
 * php-src @not-serializable intl objects:
 * - IntlDateFormatter — ext/intl/dateformat/dateformat.stub.php (#23092)
 * - NumberFormatter — ext/intl/formatter/formatter.stub.php (#23092)
 * - Collator — ext/intl/collator/collator.stub.php (#23092)
 * - MessageFormatter — ext/intl/msgformat/msgformat.stub.php (#23092)
 * - IntlListFormatter — ext/intl/listformatter/listformatter.stub.php (#23229)
 * - ResourceBundle — ext/intl/resourcebundle/resourcebundle.stub.php (#23092)
 * - IntlCalendar / IntlGregorianCalendar (+ subclasses) — ext/intl/calendar/calendar.stub.php (#23092)
 * - Transliterator — ext/intl/transliterator/transliterator.stub.php (#23136)
 * - Spoofchecker — ext/intl/spoofchecker/spoofchecker.stub.php (#23136)
 * - UConverter — ext/intl/converter/converter.stub.php (#23136)
 * - IntlTimeZone — ext/intl/timezone/timezone.stub.php (#23136)
 * - IntlBreakIterator (+ rule-based / code-point) / IntlPartsIterator — breakiterator.stub.php (#23136)
 */
final class IntlSerializeDeny
{
    /** @var list<string> */
    private const DENIED_LC = [
        VmIntlDateFormatter::CLASS_LC,
        VmNumberFormatter::CLASS_LC,
        VmCollator::CLASS_LC,
        VmMessageFormatter::CLASS_LC,
        VmIntlListFormatter::CLASS_LC,
        VmResourceBundle::CLASS_LC,
        VmIntlCalendar::CLASS_LC,
        VmIntlCalendar::GREGORIAN_CLASS_LC,
        VmTransliterator::CLASS_LC,
        VmSpoofchecker::CLASS_LC,
        VmUConverter::CLASS_LC,
        VmIntlTimeZone::CLASS_LC,
        VmBreakIterator::CLASS_LC,
        VmBreakIterator::RULE_BASED_LC,
        VmBreakIterator::CODE_POINT_LC,
        VmBreakIterator::PARTS_LC,
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
        // User subclasses inherit deny from IntlCalendar / IntlBreakIterator
        // (php-src @not-serializable on the base classes).
        $entry = $ctx->classes[$lc] ?? null;
        while (null !== $entry) {
            $nameLc = strtolower($entry->name);
            if (self::isDeniedBase($nameLc)) {
                return true;
            }
            $parentLc = $entry->parentLc;
            if (null === $parentLc || '' === $parentLc) {
                break;
            }
            if (self::isDeniedBase($parentLc)) {
                return true;
            }
            $entry = $ctx->classes[$parentLc] ?? null;
        }

        return false;
    }

    private static function isDeniedBase(string $nameLc): bool
    {
        return VmIntlCalendar::CLASS_LC === $nameLc
            || VmIntlCalendar::GREGORIAN_CLASS_LC === $nameLc
            || VmBreakIterator::CLASS_LC === $nameLc
            || VmBreakIterator::RULE_BASED_LC === $nameLc
            || VmBreakIterator::CODE_POINT_LC === $nameLc;
    }

    private static function displayName(string $className): string
    {
        return ltrim($className, '\\');
    }
}
