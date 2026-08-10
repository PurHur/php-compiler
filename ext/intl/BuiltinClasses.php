<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\ClassConstName;
use PHPCompiler\CompilerVersion;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;

/**
 * Register intl builtin classes (php-src ext/intl/php_intl.c; issues #5774, #6696, #19549, #6151, #19670).
 *
 * Locale / IntlDateFormatter / IntlDatePatternGenerator / IntlCalendar / IntlTimeZone /
 * NumberFormatter / Normalizer / Collator / MessageFormatter / IntlListFormatter /
 * Transliterator / ResourceBundle / IntlBreakIterator / IntlCodePointBreakIterator /
 * IntlChar / UConverter / Spoofchecker / IntlException all gate on {@see IntlExtensionPolicy}
 * advertisement (no phantom class_exists; #6366, #6171, #6139, #6188, #20035, #20740, #20822, #23229).
 */
final class BuiltinClasses
{
    public static function registerLocale(Context $ctx): void
    {
        $before = array_keys($ctx->classes);
        self::registerLocaleClass($ctx);
        foreach (array_diff(array_keys($ctx->classes), $before) as $lc) {
            $ctx->classes[$lc]->isInternal = true;
        }
    }

    public static function registerIntlDateFormatter(Context $ctx): void
    {
        $before = array_keys($ctx->classes);
        self::registerIntlDateFormatterClass($ctx);
        VmIntlDatePatternGenerator::registerClass($ctx);
        foreach (array_diff(array_keys($ctx->classes), $before) as $lc) {
            $ctx->classes[$lc]->isInternal = true;
        }
    }

    public static function registerIntlCalendar(Context $ctx): void
    {
        $before = array_keys($ctx->classes);
        VmIntlIterator::registerClass($ctx);
        VmIntlTimeZone::registerClass($ctx);
        VmIntlCalendar::registerClass($ctx);
        foreach (array_diff(array_keys($ctx->classes), $before) as $lc) {
            $ctx->classes[$lc]->isInternal = true;
        }
    }

    public static function registerNumberFormatter(Context $ctx): void
    {
        $before = array_keys($ctx->classes);
        VmNumberFormatter::registerClass($ctx);
        foreach (array_diff(array_keys($ctx->classes), $before) as $lc) {
            $ctx->classes[$lc]->isInternal = true;
        }
    }

    public static function register(Context $ctx): void
    {
        $before = array_keys($ctx->classes);
        self::registerIntlDateFormatterClass($ctx);
        VmIntlDatePatternGenerator::registerClass($ctx);
        VmIntlIterator::registerClass($ctx);
        VmIntlTimeZone::registerClass($ctx);
        VmIntlCalendar::registerClass($ctx);
        VmNumberFormatter::registerClass($ctx);
        VmCollator::registerClass($ctx);
        VmMessageFormatter::registerClass($ctx);
        if (IntlExtensionPolicy::advertisesIntlListFormatter()) {
            VmIntlListFormatter::registerClass($ctx);
        }
        VmTransliterator::registerClass($ctx);
        VmResourceBundle::registerClass($ctx);
        VmBreakIterator::registerClass($ctx);
        VmIntlChar::registerClass($ctx);
        VmUConverter::registerClass($ctx);
        VmSpoofchecker::registerClass($ctx);
        self::registerIntlException($ctx);
        foreach (array_diff(array_keys($ctx->classes), $before) as $lc) {
            $ctx->classes[$lc]->isInternal = true;
        }
    }

    public static function registerNormalizer(Context $ctx): void
    {
        $entry = new ClassEntry('Normalizer');
        $entry->isInternal = true;
        // Exact Zend casing for defined()/hasConstant after #25910 (#30000 / #28132).
        foreach (VmNormalizer::classConstants() as $name => $value) {
            $key = ClassConstName::key($name);
            $const = new \PHPCompiler\VM\Variable(\PHPCompiler\VM\Variable::TYPE_INTEGER);
            $const->int($value);
            $entry->constants[$key] = $const;
            $entry->constNames[$key] = $name;
        }
        $pubStatic = CfgFunc::FLAG_PUBLIC | CfgFunc::FLAG_STATIC;
        $methods = [
            'normalize' => [new NormalizerNormalize(), 'normalize'],
            'isnormalized' => [new NormalizerIsNormalized(), 'isNormalized'],
            'getrawdecomposition' => [new NormalizerGetRawDecomposition(), 'getRawDecomposition'],
        ];
        foreach ($methods as $lc => [$handler, $name]) {
            $entry->methods[$lc] = $handler;
            $entry->methodVisibility[$lc] = $pubStatic;
            $entry->methodNames[$lc] = $name;
        }
        $ctx->classes['normalizer'] = $entry;
    }

    private static function registerLocaleClass(Context $ctx): void
    {
        if (isset($ctx->classes['locale'])) {
            return;
        }

        $entry = new ClassEntry('Locale');
        $pubStatic = CfgFunc::FLAG_PUBLIC | CfgFunc::FLAG_STATIC;
        $methods = [
            'getdefault' => [new LocaleGetDefault(), 'getDefault'],
            'setdefault' => [new LocaleSetDefault(), 'setDefault'],
            'getprimarylanguage' => [new LocaleGetPrimaryLanguage(), 'getPrimaryLanguage'],
            'getregion' => [new LocaleGetRegion(), 'getRegion'],
            'getscript' => [new LocaleGetScript(), 'getScript'],
            'getdisplayname' => [new LocaleGetDisplayName(), 'getDisplayName'],
            'getdisplaylanguage' => [new LocaleGetDisplayLanguage(), 'getDisplayLanguage'],
            'getdisplayregion' => [new LocaleGetDisplayRegion(), 'getDisplayRegion'],
            'getdisplayscript' => [new LocaleGetDisplayScript(), 'getDisplayScript'],
            'getdisplayvariant' => [new LocaleGetDisplayVariant(), 'getDisplayVariant'],
            'getallvariants' => [new LocaleGetAllVariants(), 'getAllVariants'],
            'lookup' => [new LocaleLookup(), 'lookup'],
            'filtermatches' => [new LocaleFilterMatches(), 'filterMatches'],
            'acceptfromhttp' => [new LocaleAcceptFromHttp(), 'acceptFromHttp'],
            'canonicalize' => [new LocaleCanonicalize(), 'canonicalize'],
            'parselocale' => [new LocaleParseLocale(), 'parseLocale'],
            'composelocale' => [new LocaleComposeLocale(), 'composeLocale'],
            'getkeywords' => [new LocaleGetKeywords(), 'getKeywords'],
        ];
        if (IntlExtensionPolicy::advertisesLocaleRtlAndLikelySubtags()) {
            $methods['isrighttoleft'] = [new LocaleIsRightToLeft(), 'isRightToLeft'];
            $methods['addlikelysubtags'] = [new LocaleAddLikelySubtags(), 'addLikelySubtags'];
            $methods['minimizesubtags'] = [new LocaleMinimizeSubtags(), 'minimizeSubtags'];
        }
        if (IntlExtensionPolicy::advertisesLocaleDisplayKeyword()) {
            $methods['getdisplaykeyword'] = [new LocaleGetDisplayKeyword(), 'getDisplayKeyword'];
            $methods['getdisplaykeywordvalue'] = [new LocaleGetDisplayKeywordValue(), 'getDisplayKeywordValue'];
        }
        foreach ($methods as $lc => [$handler, $name]) {
            $entry->methods[$lc] = $handler;
            $entry->methodVisibility[$lc] = $pubStatic;
            $entry->methodNames[$lc] = $name;
        }
        $ctx->classes['locale'] = $entry;
    }

    private static function registerIntlDateFormatterClass(Context $ctx): void
    {
        if (isset($ctx->classes[VmIntlDateFormatter::CLASS_LC])) {
            return;
        }

        $entry = new ClassEntry('IntlDateFormatter');
        $entry->isInternal = true;
        foreach (VmIntlDateFormatter::classConstants() as $name => $value) {
            $lc = strtolower($name);
            $const = new \PHPCompiler\VM\Variable(\PHPCompiler\VM\Variable::TYPE_INTEGER);
            $const->int($value);
            $entry->constants[$lc] = $const;
            $entry->constNames[$lc] = $name;
        }
        $pub = CfgFunc::FLAG_PUBLIC;
        $pubStatic = $pub | CfgFunc::FLAG_STATIC;
        // php-src dateformat_class.c — __construct shares init with create (#21097)
        $construct = new IntlDateFormatterConstruct();
        $entry->constructor = $construct;
        $entry->methods['__construct'] = $construct;
        $entry->methodVisibility['__construct'] = $pub;
        $entry->methodNames['__construct'] = '__construct';
        $entry->methods['create'] = new IntlDateFormatterCreate();
        $entry->methodVisibility['create'] = $pubStatic;
        $entry->methodNames['create'] = 'create';
        $entry->methods['format'] = new IntlDateFormatterFormat();
        $entry->methodVisibility['format'] = $pub;
        $entry->methodNames['format'] = 'format';
        $entry->methods['formatobject'] = new IntlDateFormatterFormatObject();
        $entry->methodVisibility['formatobject'] = $pubStatic;
        $entry->methodNames['formatobject'] = 'formatObject';
        $entry->methods['getpattern'] = new IntlDateFormatterGetPattern();
        $entry->methodVisibility['getpattern'] = $pub;
        $entry->methodNames['getpattern'] = 'getPattern';
        $entry->methods['setpattern'] = new IntlDateFormatterSetPattern();
        $entry->methodVisibility['setpattern'] = $pub;
        $entry->methodNames['setpattern'] = 'setPattern';
        $entry->methods['getlocale'] = new IntlDateFormatterGetLocale();
        $entry->methodVisibility['getlocale'] = $pub;
        $entry->methodNames['getlocale'] = 'getLocale';
        $entry->methods['getdatetype'] = new IntlDateFormatterGetDateType();
        $entry->methodVisibility['getdatetype'] = $pub;
        $entry->methodNames['getdatetype'] = 'getDateType';
        $entry->methods['gettimetype'] = new IntlDateFormatterGetTimeType();
        $entry->methodVisibility['gettimetype'] = $pub;
        $entry->methodNames['gettimetype'] = 'getTimeType';
        $entry->methods['islenient'] = new IntlDateFormatterIsLenient();
        $entry->methodVisibility['islenient'] = $pub;
        $entry->methodNames['islenient'] = 'isLenient';
        $entry->methods['setlenient'] = new IntlDateFormatterSetLenient();
        $entry->methodVisibility['setlenient'] = $pub;
        $entry->methodNames['setlenient'] = 'setLenient';
        $entry->methods['getcalendar'] = new IntlDateFormatterGetCalendar();
        $entry->methodVisibility['getcalendar'] = $pub;
        $entry->methodNames['getcalendar'] = 'getCalendar';
        $entry->methods['setcalendar'] = new IntlDateFormatterSetCalendar();
        $entry->methodVisibility['setcalendar'] = $pub;
        $entry->methodNames['setcalendar'] = 'setCalendar';
        $entry->methods['gettimezoneid'] = new IntlDateFormatterGetTimeZoneId();
        $entry->methodVisibility['gettimezoneid'] = $pub;
        $entry->methodNames['gettimezoneid'] = 'getTimeZoneId';
        $entry->methods['getcalendarobject'] = new IntlDateFormatterGetCalendarObject();
        $entry->methodVisibility['getcalendarobject'] = $pub;
        $entry->methodNames['getcalendarobject'] = 'getCalendarObject';
        $entry->methods['parse'] = new IntlDateFormatterParse();
        $entry->methodVisibility['parse'] = $pub;
        $entry->methodNames['parse'] = 'parse';
        // PHP 8.4+ only — Zend 8.2 method_exists false (#22621, re-#20729).
        if (CompilerVersion::supportsIntlDateFormatterParseToCalendar()) {
            $entry->methods['parsetocalendar'] = new IntlDateFormatterParseToCalendar();
            $entry->methodVisibility['parsetocalendar'] = $pub;
            $entry->methodNames['parsetocalendar'] = 'parseToCalendar';
        }
        $entry->methods['localtime'] = new IntlDateFormatterLocaltime();
        $entry->methodVisibility['localtime'] = $pub;
        $entry->methodNames['localtime'] = 'localtime';
        $entry->methods['gettimezone'] = new IntlDateFormatterGetTimeZone();
        $entry->methodVisibility['gettimezone'] = $pub;
        $entry->methodNames['gettimezone'] = 'getTimeZone';
        $entry->methods['settimezone'] = new IntlDateFormatterSetTimeZone();
        $entry->methodVisibility['settimezone'] = $pub;
        $entry->methodNames['settimezone'] = 'setTimeZone';
        $entry->methods['geterrorcode'] = new IntlDateFormatterGetErrorCode();
        $entry->methodVisibility['geterrorcode'] = $pub;
        $entry->methodNames['geterrorcode'] = 'getErrorCode';
        $entry->methods['geterrormessage'] = new IntlDateFormatterGetErrorMessage();
        $entry->methodVisibility['geterrormessage'] = $pub;
        $entry->methodNames['geterrormessage'] = 'getErrorMessage';
        $ctx->classes[VmIntlDateFormatter::CLASS_LC] = $entry;
    }

    public static function registerCollator(Context $ctx): void
    {
        $before = array_keys($ctx->classes);
        VmCollator::registerClass($ctx);
        foreach (array_diff(array_keys($ctx->classes), $before) as $lc) {
            $ctx->classes[$lc]->isInternal = true;
        }
    }

    public static function registerMessageFormatter(Context $ctx): void
    {
        $before = array_keys($ctx->classes);
        VmMessageFormatter::registerClass($ctx);
        self::registerIntlException($ctx);
        foreach (array_diff(array_keys($ctx->classes), $before) as $lc) {
            $ctx->classes[$lc]->isInternal = true;
        }
    }

    /** IntlListFormatter — PHP 8.5+ + host intl (#23229). */
    public static function registerIntlListFormatter(Context $ctx): void
    {
        $before = array_keys($ctx->classes);
        VmIntlListFormatter::registerClass($ctx);
        self::registerIntlException($ctx);
        foreach (array_diff(array_keys($ctx->classes), $before) as $lc) {
            $ctx->classes[$lc]->isInternal = true;
        }
    }

    public static function registerTransliterator(Context $ctx): void
    {
        $before = array_keys($ctx->classes);
        VmTransliterator::registerClass($ctx);
        foreach (array_diff(array_keys($ctx->classes), $before) as $lc) {
            $ctx->classes[$lc]->isInternal = true;
        }
    }

    public static function registerResourceBundle(Context $ctx): void
    {
        $before = array_keys($ctx->classes);
        VmResourceBundle::registerClass($ctx);
        foreach (array_diff(array_keys($ctx->classes), $before) as $lc) {
            $ctx->classes[$lc]->isInternal = true;
        }
    }

    public static function registerBreakIterator(Context $ctx): void
    {
        $before = array_keys($ctx->classes);
        VmBreakIterator::registerClass($ctx);
        foreach (array_diff(array_keys($ctx->classes), $before) as $lc) {
            $ctx->classes[$lc]->isInternal = true;
        }
    }

    /** IntlChar — gated with ext/intl (#6171). */
    public static function registerIntlChar(Context $ctx): void
    {
        $before = array_keys($ctx->classes);
        VmIntlChar::registerClass($ctx);
        foreach (array_diff(array_keys($ctx->classes), $before) as $lc) {
            $ctx->classes[$lc]->isInternal = true;
        }
    }

    /** UConverter — gated with ext/intl (#6171). */
    public static function registerUConverter(Context $ctx): void
    {
        $before = array_keys($ctx->classes);
        VmUConverter::registerClass($ctx);
        foreach (array_diff(array_keys($ctx->classes), $before) as $lc) {
            $ctx->classes[$lc]->isInternal = true;
        }
    }

    /** Spoofchecker — gated with ext/intl (#20035 / deferred #6171). */
    public static function registerSpoofchecker(Context $ctx): void
    {
        $before = array_keys($ctx->classes);
        VmSpoofchecker::registerClass($ctx);
        foreach (array_diff(array_keys($ctx->classes), $before) as $lc) {
            $ctx->classes[$lc]->isInternal = true;
        }
    }

    private static function registerIntlException(Context $ctx): void
    {
        if (isset($ctx->classes['intlexception'])) {
            return;
        }
        // Prefer ThrowableManifest registration (properties + ExceptionConstruct). Forced
        // MessageFormatter registration without host intl still needs a usable class (#22577).
        $entry = new ClassEntry('IntlException');
        if (isset($ctx->classes['exception'])) {
            $parent = $ctx->classes['exception'];
            $entry->parentLc = 'exception';
            foreach ($parent->properties as $prop) {
                $entry->properties[] = $prop;
            }
            foreach ($parent->methods as $lc => $handler) {
                $entry->methods[$lc] = $handler;
                if (isset($parent->methodVisibility[$lc])) {
                    $entry->methodVisibility[$lc] = $parent->methodVisibility[$lc];
                }
                if (isset($parent->methodNames[$lc])) {
                    $entry->methodNames[$lc] = $parent->methodNames[$lc];
                }
            }
            $entry->constructor = $parent->constructor;
        }
        $ctx->classes['intlexception'] = $entry;
    }
}
