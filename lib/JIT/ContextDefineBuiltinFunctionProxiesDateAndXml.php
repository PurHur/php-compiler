<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\CompilerVersion;

/**
 * DateTime / DateInterval / DatePeriod / finfo / PDO / XMLReader / XMLWriter /
 * Dom\\TokenList thin-AOT Call proxies for {@see Context} (#36387).
 *
 * Extracted from {@see ContextDefineBuiltinFunctionProxies} so the date/XML
 * catalog stays a separate TU from the SPL / Reflection proxy wiring (split-TU
 * / size-budget ratchet toward ContextDefineBuiltinFunctionProxies ≤ 700 lines,
 * #36199 / #36403).
 *
 * Used via {@code use ContextDefineBuiltinFunctionProxiesDateAndXml;} on
 * {@see Context}. Invoked from {@see ContextDefineBuiltinFunctionProxies::defineBuiltinFunctionProxies}
 * after Fiber / Generator / ClosureBind helper registration.
 *
 * No new C ABI. php-src analogy: zim_DateTime* / zim_PDO* / XMLReader method
 * tables live in ext/date/, ext/pdo/, ext/xmlreader/ beside the executor rather
 * than inside a monolithic MINIT catalog (ext/date/php_date.c, ext/pdo/pdo.c,
 * ext/xmlreader/php_xmlreader.c).
 */
trait ContextDefineBuiltinFunctionProxiesDateAndXml
{
    private function defineBuiltinFunctionProxiesDateAndXml(): void
    {
        // DateTime / DateInterval / DatePeriod ctors — thin user-script AOT (#26772).
        $this->functionProxies['datetime::__construct'] = new Call\DateTimeConstruct();
        $this->functionProxies['datetimeimmutable::__construct'] = new Call\DateTimeImmutableConstruct();
        // DOMDocument / Dom\ living factories: ext/dom/Module::jitInit (#36204 / #33607).
        // ZipArchive / SQLite3 thin-AOT Call proxies: registered by ext/zip + ext/sqlite3 Module::jitInit (#36204).
        $this->functionProxies['datetimezone::__construct'] = new Call\DateTimeZoneConstruct();
        $this->functionProxies['dateinterval::__construct'] = new Call\DateIntervalConstruct();
        $this->functionProxies['dateinterval::format'] = new Call\DateIntervalFormat();
        $this->functionProxies['dateperiod::__construct'] = new Call\DatePeriodConstruct();
        if (CompilerVersion::supportsDatePeriodCreateFromISO8601String()) {
            $this->functionProxies['dateperiod::createfromiso8601string'] = new Call\DatePeriodCreateFromISO8601String();
            foreach (['rewind', 'valid', 'current', 'key', 'next'] as $dpIterMethod) {
                $this->functionProxies['dateperiod::'.$dpIterMethod] = new Call\DatePeriodIteratorMethod($dpIterMethod);
            }
        }
        // Accessors — always (not gated on ISO8601); avoid ExternalMethod null stubs (#27572).
        foreach (['getstartdate', 'getenddate', 'getdateinterval', 'getrecurrences'] as $dpAcc) {
            $this->functionProxies['dateperiod::'.$dpAcc] = new Call\DatePeriodAccessorMethod($dpAcc);
        }
        $this->functionProxies['datetime::format'] = new Call\DateTimeFormat();
        $this->functionProxies['datetimeimmutable::format'] = new Call\DateTimeFormat();
        // Wire class static factories to date_create*_from_format JIT (#26788 / #6172).
        $this->functionProxies['datetime::createfromformat'] = new Call\DateTimeCreateFromFormat(false);
        $this->functionProxies['datetimeimmutable::createfromformat'] = new Call\DateTimeCreateFromFormat(true);
        // Conversion factories — avoid ExternalMethod null / segfault on thin AOT (#30762).
        $this->functionProxies['datetime::createfrominterface'] = new Call\DateTimeCreateFromInterface(false);
        $this->functionProxies['datetimeimmutable::createfrominterface'] = new Call\DateTimeCreateFromInterface(true);
        $this->functionProxies['datetime::createfromimmutable'] = new Call\DateTimeCreateFromImmutable();
        $this->functionProxies['datetimeimmutable::createfrommutable'] = new Call\DateTimeImmutableCreateFromMutable();
        // PHP 8.4+ createFromTimestamp — avoid ExternalMethod null stub abort on thin AOT (#26936).
        if (CompilerVersion::supportsDateTimeCreateFromTimestamp()) {
            $this->functionProxies['datetime::createfromtimestamp'] = new Call\DateTimeCreateFromTimestamp(false);
            $this->functionProxies['datetimeimmutable::createfromtimestamp'] = new Call\DateTimeCreateFromTimestamp(true);
        }
        // PHP 8.4+ get/setMicrosecond — avoid ExternalMethod silent NULL on thin AOT (#26938).
        if (CompilerVersion::supportsDateTimeMicrosecond()) {
            $this->functionProxies['datetime::getmicrosecond'] = new Call\DateTimeGetMicrosecond(false);
            $this->functionProxies['datetimeimmutable::getmicrosecond'] = new Call\DateTimeGetMicrosecond(true);
            $this->functionProxies['datetime::setmicrosecond'] = new Call\DateTimeSetMicrosecond(false);
            $this->functionProxies['datetimeimmutable::setmicrosecond'] = new Call\DateTimeSetMicrosecond(true);
        }
        // php-src stub $datetime — InternalArgInfo still says time (#24589).
        $this->functionProxies['dateinterval::createfromdatestring'] = new Call\DateIntervalCreateFromDateString();
        // Mutable setTimezone — thin user-script AOT property write (#22824).
        $this->functionProxies['datetime::settimezone'] = new Call\DateTimeSetTimezone(false);
        $this->functionProxies['datetime::gettimezone'] = new Call\DateTimeGetTimezone();
        $this->functionProxies['datetimeimmutable::gettimezone'] = new Call\DateTimeGetTimezone();
        // getOffset lives in DateTimeSetTimezone.php (always loaded above).
        // Avoid ExternalMethod null stub / invokeJitCall TypeError on thin AOT (#30761).
        $this->functionProxies['datetime::getoffset'] = new Call\DateTimeGetOffset();
        $this->functionProxies['datetimeimmutable::getoffset'] = new Call\DateTimeGetOffset();
        // Immutable: allocate+copy (not cloneObject) for MCJIT. Thin user-script AOT still
        // hits "basic block has no parent" inside Object_::allocate / NestedJIT ensureLinked
        // (same as `new DateTimeImmutable` under HELPER_RUNTIME_O=0). VM Builtin covers
        // php bin/vm.php; register proxy for MCJIT / full-init only (#22824).
        if (!UserScriptAotEnv::isActive()) {
            $this->functionProxies['datetimeimmutable::settimezone'] = new Call\DateTimeSetTimezone(true);
        }
        // getTimestamp / setTimestamp live in DateTimeSetTimezone.php (always loaded above).
        // Avoid ExternalMethod null stub on thin AOT (#30745).
        $this->functionProxies['datetime::gettimestamp'] = new Call\DateTimeGetTimestamp(false);
        $this->functionProxies['datetimeimmutable::gettimestamp'] = new Call\DateTimeGetTimestamp(true);
        $this->functionProxies['datetime::settimestamp'] = new Call\DateTimeSetTimestamp(false);
        // Immutable allocate+copy is thin-AOT-safe after DatePeriod foreach (#26772 / modify).
        $this->functionProxies['datetimeimmutable::settimestamp'] = new Call\DateTimeSetTimestamp(true);
        // setDate / setTime live in DateTimeSetTimezone.php (always loaded above).
        // Avoid ExternalMethod null stub on thin AOT (#30747).
        $this->functionProxies['datetime::setdate'] = new Call\DateTimeSetDate(false);
        $this->functionProxies['datetimeimmutable::setdate'] = new Call\DateTimeSetDate(true);
        $this->functionProxies['datetime::settime'] = new Call\DateTimeSetTime(false);
        $this->functionProxies['datetimeimmutable::settime'] = new Call\DateTimeSetTime(true);
        $this->functionProxies['datetime::setisodate'] = new Call\DateTimeSetISODate(false);
        $this->functionProxies['datetimeimmutable::setisodate'] = new Call\DateTimeSetISODate(true);
        // getLastErrors() — compile-time last-errors bag (peer date_get_last_errors) (#30749).
        $this->functionProxies['datetime::getlasterrors'] = new Call\DateTimeGetLastErrors(false);
        $this->functionProxies['datetimeimmutable::getlasterrors'] = new Call\DateTimeGetLastErrors(true);
        // modify() — avoid ExternalMethod null stub segfault after chained format() (#26789).
        // Immutable allocate+copy is thin-AOT-safe after DatePeriod foreach (#26772).
        $this->functionProxies['datetime::modify'] = new Call\DateTimeModify(false);
        $this->functionProxies['datetimeimmutable::modify'] = new Call\DateTimeModify(true);
        // add()/sub() — avoid ExternalMethod null stub segfault / silent no-op (#30760).
        // DateTimeAdd/DateTimeSub live in DateTimeModify.php (always loaded above).
        $this->functionProxies['datetime::add'] = new Call\DateTimeAdd(false);
        $this->functionProxies['datetimeimmutable::add'] = new Call\DateTimeAdd(true);
        $this->functionProxies['datetime::sub'] = new Call\DateTimeSub(false);
        $this->functionProxies['datetimeimmutable::sub'] = new Call\DateTimeSub(true);
        // Procedural date_add/date_sub — Call proxy avoids Internal FUNCCALL prep SIGSEGV (#33781).
        $this->functionProxies['date_add'] = new Call\ProceduralDateAdd();
        $this->functionProxies['date_sub'] = new Call\ProceduralDateSub();
        // DateTime::diff — compile-time DateInterval materialize (#27309).
        $this->functionProxies['datetime::diff'] = new Call\DateTimeDiff();
        $this->functionProxies['datetimeimmutable::diff'] = new Call\DateTimeDiff();
        // DateTimeZone::getTransitions — compile-time materialize (peer timezone_transitions_get) (#26799).
        $this->functionProxies['datetimezone::gettransitions'] = new Call\DateTimeZoneGetTransitions();
        // DateTimeZone::getName — avoid ExternalMethod silent NULL on thin AOT (#27307).
        $this->functionProxies['datetimezone::getname'] = new Call\DateTimeZoneGetName();
        // php-src zim_DateTimeZone_getLocation — thin AOT was silent NULL (#33727).
        $this->functionProxies['datetimezone::getlocation'] = new Call\DateTimeZoneGetLocation();
        // DateTimeZone::getOffset — avoid ExternalMethod silent NULL on thin AOT (#27308).
        $this->functionProxies['datetimezone::getoffset'] = new Call\DateTimeZoneGetOffset();
        // DateTimeZone::listIdentifiers — avoid ExternalMethod silent NULL on thin AOT (#29735).
        $this->functionProxies['datetimezone::listidentifiers'] = new Call\DateTimeZoneListIdentifiers();
        // DateTimeZone::listAbbreviations — avoid ExternalMethod silent NULL on thin AOT (#30780).
        $this->functionProxies['datetimezone::listabbreviations'] = new Call\DateTimeZoneListAbbreviations();
        // Locale::* + NumberFormatter / IntlDateFormatter / Collator / Normalizer /
        // MessageFormatter / Transliterator thin-AOT Call proxies:
        // registered by ext/intl/Module::jitInit (#36204 / #20760 / #27385 / #27361 / #28649 / #28654 / #28655 / #28657).
        // finfo::__construct / finfo::file / finfo::buffer / finfo::set_flags — thin AOT (#27196, #28660, #34688).
        $this->functionProxies['finfo::__construct'] = new Call\FinfoConstruct();
        $this->functionProxies['finfo::file'] = new Call\FinfoFile();
        $this->functionProxies['finfo::buffer'] = new Call\FinfoBuffer();
        $this->functionProxies['finfo::set_flags'] = new Call\FinfoSetFlags();
        // PDO — avoid ExternalMethod silent NULL / fake connect (#27619).
        $this->functionProxies['pdo::__construct'] = new Call\PdoConstruct();
        $this->functionProxies['pdo::getavailabledrivers'] = new Call\PdoGetAvailableDrivers();
        $this->functionProxies['pdo::quote'] = new Call\PdoQuote();
        // Dom\XMLDocument / Dom\HTMLDocument::createFromString / createFromFile +
        // DOMDocument::__construct: ext/dom/Module::jitInit (#36204 / #27108, #27300, #33607).
        // XMLReader::XML / fromString / read — avoid ExternalMethod silent NULL on thin AOT (#27299, #28670).
        // XML() exists on all profiles; fromString is PROFILE≥8.4 only.
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::xml');
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::open');
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::read');
        // leftover of fromString read (#35908 / #27299) — php-src readInnerXml / readOuterXml
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::readinnerxml');
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::readouterxml');
        // leftover of fromString/readInnerXml (#35917 / #27299)
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::readstring');
        // leftover of fromString/open (#35911 / #27299)
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::expand');
        // leftover of fromString/read (#35926 / #27299)
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::next');
        // leftover of fromString/read (#35959 / #27299)
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::isvalid');
        // leftover of fromString/read (#35965 / #27299)
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::setparserproperty');
        // leftover of fromString (#35971 / #27299)
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::setschema');
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::setrelaxngschema');
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::setrelaxngschemasource');
        // leftover of fromString (#35935 / #27299)
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::close');
        // leftover of getAttribute (#35941 / #35918 / #27299)
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::movetoattribute');
        // leftover of moveToAttribute (#35946 / #35941 / #27299)
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::movetoattributeno');
        // leftover of moveToAttribute (#35948 / #35941 / #27299)
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::movetofirstattribute');
        // leftover of moveToAttribute (#35951 / #35941 / #27299)
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::movetoattributens');
        // leftover of moveToAttribute (#35940 / #27299)
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::movetoelement');
        // leftover of moveToAttribute (#35952 / #35941 / #27299)
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::movetonextattribute');
        // leftover of fromString/read (#35962 / #27299)
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::getparserproperty');
        if (CompilerVersion::supportsXmlReaderFactories()) {
            XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::fromstring');
            // leftover of fromString (#35900 / #27299)
            XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::fromuri');
            XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::fromstream');
        }
        // XMLWriter::toMemory / toUri / toStream — leftover of openMemory/openUri (#19606 / #35872 / #35895).
        if (CompilerVersion::supportsXmlWriterFactories()) {
            XmlWriterInstanceMethodJit::ensureProxy($this, 'xmlwriter::tomemory');
            XmlWriterInstanceMethodJit::ensureProxy($this, 'xmlwriter::touri');
            XmlWriterInstanceMethodJit::ensureProxy($this, 'xmlwriter::tostream');
        }
        if (CompilerVersion::supportsDomTokenList()) {
            DomInstanceMethodJit::registerKnownProxies($this);
        }
    }
}
