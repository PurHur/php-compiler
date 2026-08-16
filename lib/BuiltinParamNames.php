<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * PHP parameter names for VM builtins (named arguments, issue #168).
 */
final class BuiltinParamNames
{
    /**
     * php-src stub parameter names for VM builtin class methods (#11785, ext/date/php_date.stub.php).
     *
     * @return list<string>|null
     */
    public static function forClassMethod(string $qualifiedMethod): ?array
    {
        return match (strtolower($qualifiedMethod)) {
            'datetime::createfromformat',
            'datetimeimmutable::createfromformat' => ['format', 'datetime', 'timezone='],
            'datetime::__construct',
            'datetimeimmutable::__construct' => ['datetime', 'timezone'],
            'datetime::format' => ['format'],
            'datetimeimmutable::format' => ['format'],
            // php-src ext/date/php_date.stub.php — InternalArgInfo still says modify (#23685)
            'datetime::modify',
            'datetimeimmutable::modify' => ['modifier'],
            'datetime::add',
            'datetimeimmutable::add',
            'datetime::sub',
            'datetimeimmutable::sub' => ['interval'],
            // php-src ext/date/php_date.stub.php — InternalArgInfo omits microsecond; Immutable second required (#25400)
            'datetime::settime',
            'datetimeimmutable::settime' => ['hour', 'minute', 'second=', 'microsecond='],
            // php-src ext/date/php_date.stub.php — PHP 8.4+; missing from InternalArgInfo (#26098)
            'datetime::setmicrosecond',
            'datetimeimmutable::setmicrosecond' => ['microsecond'],
            // php-src ext/date/php_date.stub.php — PHP 8.4+ createFromTimestamp(int|float $timestamp): static (#26097)
            'datetime::createfromtimestamp',
            'datetimeimmutable::createfromtimestamp' => ['timestamp'],
            // php-src ext/date/php_date.stub.php — createFromInterface(DateTimeInterface $object) (#28896)
            'datetime::createfrominterface',
            'datetimeimmutable::createfrominterface' => ['object'],
            // php-src ext/date/php_date.stub.php — createFromImmutable / createFromMutable (#30762)
            'datetime::createfromimmutable' => ['object'],
            'datetimeimmutable::createfrommutable' => ['object'],
            // php-src ext/pdo/pdo_dbh.stub.php — InternalArgInfo still passwd/statement/sql + required (#24590)
            'pdo::__construct' => ['dsn', 'username=', 'password=', 'options='],
            'pdo::prepare' => ['query', 'options='],
            'pdo::query' => ['query', 'fetchMode=', '...fetchModeArgs'],
            // php-src ext/pdo/pdo_dbh.stub.php — PHP 8.4+; missing from InternalArgInfo (#26223)
            'pdo::connect' => ['dsn', 'username=', 'password=', 'options='],
            // php-src ext/mysqli/mysqli.stub.php — absent from InternalArgInfo (#27712)
            'mysqli::execute_query' => ['query', 'params='],
            'datetimezone::__construct' => ['timezone'],
            // php-src ext/date/php_date.stub.php — InternalArgInfo still snake_case + phantom object (#23666)
            'datetimezone::gettransitions' => ['timestampBegin=', 'timestampEnd='],
            // php-src ext/date/php_date.stub.php — InternalArgInfo still what/country (#25172)
            'datetimezone::listidentifiers' => ['timezoneGroup=', 'countryCode='],
            // php-src ext/date/php_date.stub.php — InternalArgInfo still recur; UNKNOWN optionals (#25164)
            'dateperiod::__construct' => ['start', 'interval=', 'end=', 'options='],
            // php-src ext/date/php_date.stub.php — PHP 8.4+; absent from InternalArgInfo (#27923)
            'dateperiod::createfromiso8601string' => ['specification', 'options='],
            // php-src ext/reflection/php_reflection.stub.php — InternalArgInfo marks object required (#24433)
            'reflectionmethod::getclosure' => ['object='],
            // php-src ext/reflection/php_reflection.stub.php — ctor names vs InternalArgInfo (#28939)
            'reflectionfunction::__construct' => ['function'],
            'reflectionclass::__construct' => ['objectOrClass'],
            'reflectionmethod::__construct' => ['objectOrMethod', 'method='],
            'reflectionproperty::__construct' => ['class', 'property'],
            // php-src ext/reflection/php_reflection.stub.php — PHP 8.4+; absent from InternalArgInfo (#27599)
            'reflectionproperty::getrawvalue' => ['object'],
            'reflectionproperty::setrawvalue' => ['object', 'value'],
            // php-src ext/reflection/php_reflection.stub.php — PHP 8.5+; absent from InternalArgInfo (#28533)
            'reflectionproperty::isreadable' => ['scope', 'object='],
            'reflectionproperty::iswritable' => ['scope', 'object='],
            // php-src ext/reflection/php_reflection.stub.php — PHP 8.4+ lazy factories; absent from InternalArgInfo (#27741)
            'reflectionclass::newlazyghost' => ['initializer', 'options='],
            'reflectionclass::newlazyproxy' => ['factory', 'options='],
            'reflectionclass::resetaslazyghost' => ['object', 'initializer', 'options='],
            'reflectionclass::resetaslazyproxy' => ['object', 'factory', 'options='],
            // php-src ext/reflection/php_reflection.stub.php — ...$args; Z_PARAM_VARIADIC_WITH_NAMED (#24949)
            'reflectionfunction::invoke' => ['...args='],
            'reflectionmethod::invoke' => ['object', '...args='],
            // php-src ext/date/php_date.stub.php — InternalArgInfo still says spec (#23707)
            'dateinterval::__construct' => ['duration'],
            // php-src ext/date/php_date.stub.php — InternalArgInfo still says time (#24589)
            'dateinterval::createfromdatestring' => ['datetime'],
            'errorexception::__construct' => ['message=', 'code=', 'severity=', 'filename=', 'line=', 'previous='],
            'arrayobject::__construct' => ['array', 'flags', 'iterator_class'],
            // php-src ext/spl/spl_array.stub.php — InternalArgInfo still says ar (#24493)
            'arrayobject::exchangearray' => ['array'],
            // php-src ext/spl/spl_directory.stub.php — InternalArgInfo still says file_name (#24505)
            'splfileinfo::__construct' => ['filename'],
            // php-src ext/spl/spl_directory.stub.php — InternalArgInfo still says path (#24503)
            'directoryiterator::__construct' => ['directory'],
            'filesystemiterator::__construct' => ['directory', 'flags='],
            'recursivedirectoryiterator::__construct' => ['directory', 'flags='],
            'splfileobject::seek' => ['line'],
            'splfileobject::fgetcsv' => ['separator', 'enclosure', 'escape'],
            // php-src ext/spl/spl_directory.stub.php — trailing CSV args optional; InternalArgInfo omits `=`/`eol` (#25135)
            'splfileobject::fputcsv' => ['fields', 'separator=', 'enclosure=', 'escape=', 'eol='],
            // php-src ext/spl/spl_heap.stub.php — InternalArgInfo still a/b (#25555)
            'splheap::compare',
            'splminheap::compare',
            'splmaxheap::compare' => ['value1', 'value2'],
            'splpriorityqueue::compare' => ['priority1', 'priority2'],
            // php-src ext/spl/spl_iterators.stub.php — InternalArgInfo still it/func (#28721)
            'callbackfilteriterator::__construct',
            'recursivecallbackfilteriterator::__construct' => ['iterator', 'callback'],
            // php-src ext/spl/spl_iterators.stub.php — InternalArgInfo still it/regex/preg_flags (#31511)
            'regexiterator::__construct',
            'recursiveregexiterator::__construct' => ['iterator', 'pattern', 'mode=', 'flags=', 'pregFlags='],
            'collator::create' => ['locale'],
            'collator::compare' => ['string1', 'string2'],
            'collator::asort' => ['array', 'flags'],
            // php-src ext/intl/collator/collator.stub.php — OOP getSortKey(string $string); InternalArgInfo still coll/str (#28785)
            'collator::getsortkey' => ['string'],
            'messageformatter::create' => ['locale', 'pattern'],
            'messageformatter::format' => ['args'],
            'messageformatter::setpattern' => ['pattern'],
            'messageformatter::getpattern' => [],
            'messageformatter::formatmessage' => ['locale', 'pattern', 'args'],
            // php-src ext/intl/formatter/formatter.stub.php — InternalArgInfo still has num/str/position (#23409)
            'numberformatter::formatcurrency' => ['amount', 'currency'],
            'numberformatter::parsecurrency' => ['string', 'currency', 'offset'],
            // php-src ext/intl/spoofchecker/spoofchecker.stub.php — missing from php-types ArgInfo (#25055)
            'spoofchecker::issuspicious' => ['string', '&errorCode='],
            'spoofchecker::areconfusable' => ['string1', 'string2', '&errorCode='],
            'transliterator::create' => ['id', 'direction'],
            'transliterator::transliterate' => ['string', 'start', 'end'],
            'resourcebundle::create' => ['locale', 'bundle', 'fallback='],
            // php-src resourcebundle.stub.php — __construct(?string $locale, ?string $bundle, bool $fallback = true) (#25056)
            'resourcebundle::__construct' => ['locale', 'bundle', 'fallback='],
            'resourcebundle::get' => ['index'],
            'resourcebundle::count' => [],
            // php-src timezone.stub.php — ICU≥74 (#21553)
            'intltimezone::getianaid' => ['zoneId'],
            // php-src ext/intl/calendar/calendar.stub.php — both optional; $timezone untyped (#28482)
            'intlcalendar::createinstance' => ['timezone=', 'locale='],
            // php-src ext/dom/php_dom.stub.php — InternalArgInfo still has pre-stub names (#23391, #25182)
            'domdocument::adoptnode' => ['node'],
            // ParentNode / ChildNode living mutators — variadic ...$nodes (php_dom.stub.php; #25742)
            'domdocument::append' => ['...nodes'],
            'domdocument::prepend' => ['...nodes'],
            'domelement::append' => ['...nodes'],
            'domelement::prepend' => ['...nodes'],
            'domelement::before' => ['...nodes'],
            'domelement::after' => ['...nodes'],
            'domelement::replacewith' => ['...nodes'],
            'domdocumentfragment::append' => ['...nodes'],
            'domdocumentfragment::prepend' => ['...nodes'],
            'domcharacterdata::before' => ['...nodes'],
            'domcharacterdata::after' => ['...nodes'],
            'domcharacterdata::replacewith' => ['...nodes'],
            'domtext::before' => ['...nodes'],
            'domtext::after' => ['...nodes'],
            'domtext::replacewith' => ['...nodes'],
            'domcomment::before' => ['...nodes'],
            'domcomment::after' => ['...nodes'],
            'domcomment::replacewith' => ['...nodes'],
            'domcdatasection::before' => ['...nodes'],
            'domcdatasection::after' => ['...nodes'],
            'domcdatasection::replacewith' => ['...nodes'],
            // Dom\* living mirrors (PROFILE≥8.4; same stub shape)
            'dom\\document::append' => ['...nodes'],
            'dom\\document::prepend' => ['...nodes'],
            'dom\\element::append' => ['...nodes'],
            'dom\\element::prepend' => ['...nodes'],
            'dom\\element::before' => ['...nodes'],
            'dom\\element::after' => ['...nodes'],
            'dom\\element::replacewith' => ['...nodes'],
            'dom\\htmlelement::append' => ['...nodes'],
            'dom\\htmlelement::prepend' => ['...nodes'],
            'dom\\htmlelement::before' => ['...nodes'],
            'dom\\htmlelement::after' => ['...nodes'],
            'dom\\htmlelement::replacewith' => ['...nodes'],
            'dom\\documentfragment::append' => ['...nodes'],
            'dom\\documentfragment::prepend' => ['...nodes'],
            'dom\\characterdata::before' => ['...nodes'],
            'dom\\characterdata::after' => ['...nodes'],
            'dom\\characterdata::replacewith' => ['...nodes'],
            'dom\\text::before' => ['...nodes'],
            'dom\\text::after' => ['...nodes'],
            'dom\\text::replacewith' => ['...nodes'],
            'dom\\comment::before' => ['...nodes'],
            'dom\\comment::after' => ['...nodes'],
            'dom\\comment::replacewith' => ['...nodes'],
            'dom\\cdatasection::before' => ['...nodes'],
            'dom\\cdatasection::after' => ['...nodes'],
            'dom\\cdatasection::replacewith' => ['...nodes'],
            // php-src ext/dom/php_dom.stub.php — createFromString(string $source, int $options = 0, ?string $overrideEncoding = null) (#26080)
            'dom\\htmldocument::createfromstring',
            'dom\\xmldocument::createfromstring' => ['source', 'options=', 'overrideEncoding='],
            // php-src ext/dom/php_dom.stub.php — createFromFile(string $path, int $options = 0, ?string $overrideEncoding = null) (#27924)
            'dom\\htmldocument::createfromfile',
            'dom\\xmldocument::createfromfile' => ['path', 'options=', 'overrideEncoding='],
            // php-src ext/dom/php_dom.stub.php — Document/HTMLDocument/XMLDocument instance methods (#28740)
            'dom\\document::getelementbyid',
            'dom\\htmldocument::getelementbyid',
            'dom\\xmldocument::getelementbyid' => ['elementId'],
            'dom\\htmldocument::savehtml' => ['node='],
            'dom\\document::savexml',
            'dom\\htmldocument::savexml',
            'dom\\xmldocument::savexml' => ['node=', 'options='],
            // php-src ext/dom/php_dom.stub.php — Element/HTMLElement selectors (#28741)
            'dom\\element::queryselector',
            'dom\\htmlelement::queryselector',
            'dom\\document::queryselector',
            'dom\\htmldocument::queryselector',
            'dom\\xmldocument::queryselector',
            'dom\\documentfragment::queryselector',
            'dom\\element::queryselectorall',
            'dom\\htmlelement::queryselectorall',
            'dom\\document::queryselectorall',
            'dom\\htmldocument::queryselectorall',
            'dom\\xmldocument::queryselectorall',
            'dom\\documentfragment::queryselectorall',
            'dom\\element::closest',
            'dom\\htmlelement::closest',
            'dom\\element::matches',
            'dom\\htmlelement::matches' => ['selectors'],
            'dom\\element::getelementsbytagname',
            'dom\\htmlelement::getelementsbytagname',
            'dom\\document::getelementsbytagname',
            'dom\\htmldocument::getelementsbytagname',
            'dom\\xmldocument::getelementsbytagname' => ['qualifiedName'],
            'domdocument::appendchild' => ['node'],
            'domdocument::createattribute' => ['localName'],
            'domdocument::createattributens' => ['namespace', 'qualifiedName'],
            'domdocument::createelement' => ['localName', 'value'],
            'domdocument::createelementns' => ['namespace', 'qualifiedName', 'value'],
            'domdocument::createtextnode' => ['data'],
            'domdocument::getelementbyid' => ['elementId'],
            'domdocument::getelementsbytagname' => ['qualifiedName'],
            'domdocument::getelementsbytagnamens' => ['namespace', 'localName'],
            'domdocument::importnode' => ['node', 'deep'],
            'domdocument::load' => ['filename', 'options='],
            'domdocument::loadhtml' => ['source', 'options='],
            'domdocument::loadhtmlfile' => ['filename', 'options='],
            'domdocument::loadxml' => ['source', 'options='],
            'domdocument::registernodeclass' => ['baseClass', 'extendedClass'],
            'domdocument::relaxngvalidate' => ['filename'],
            'domdocument::relaxngvalidatesource' => ['source'],
            'domdocument::save' => ['filename', 'options='],
            'domdocument::savehtml' => ['node='],
            'domdocument::savehtmlfile' => ['filename'],
            'domdocument::savexml' => ['node=', 'options='],
            'domdocument::schemavalidate' => ['filename', 'flags='],
            'domdocument::schemavalidatesource' => ['source', 'flags='],
            'domdocument::xinclude' => ['options='],
            'domelement::__construct' => ['qualifiedName', 'value', 'namespace'],
            'domtext::__construct' => ['data'],
            'domcomment::__construct' => ['data'],
            'domcdatasection::__construct' => ['data'],
            'domprocessinginstruction::__construct' => ['name', 'value'],
            'domentityreference::__construct' => ['name'],
            'domattr::__construct' => ['name', 'value'],
            'domelement::appendchild' => ['node'],
            'domelement::getattribute' => ['qualifiedName'],
            'domelement::getattributenode' => ['qualifiedName'],
            'domelement::getattributenodens' => ['namespace', 'localName'],
            'domelement::getattributens' => ['namespace', 'localName'],
            'domelement::getelementsbytagname' => ['qualifiedName'],
            'domelement::getelementsbytagnamens' => ['namespace', 'localName'],
            'domelement::hasattribute' => ['qualifiedName'],
            'domelement::hasattributens' => ['namespace', 'localName'],
            'domelement::removeattribute' => ['qualifiedName'],
            'domelement::removeattributenode' => ['attr'],
            'domelement::removeattributens' => ['namespace', 'localName'],
            'domelement::setattribute' => ['qualifiedName', 'value'],
            'domelement::setattributenode' => ['attr'],
            'domelement::setattributenodens' => ['attr'],
            'domelement::setattributens' => ['namespace', 'qualifiedName', 'value'],
            'domelement::setidattribute' => ['qualifiedName', 'isId'],
            'domelement::setidattributenode' => ['attr', 'isId'],
            'domelement::setidattributens' => ['namespace', 'qualifiedName', 'isId'],
            'domimplementation::createdocument' => ['namespace', 'qualifiedName', 'doctype'],
            'domimplementation::createdocumenttype' => ['qualifiedName', 'publicId', 'systemId'],
            'domimplementation::getfeature' => ['feature', 'version'],
            'domnamednodemap::getnameditem' => ['qualifiedName'],
            'domnamednodemap::getnameditemns' => ['namespace', 'localName'],
            'domdocumentfragment::appendchild' => ['node'],
            'domnode::appendchild' => ['node'],
            'domnode::c14n' => ['exclusive=', 'withComments=', 'xpath=', 'nsPrefixes='],
            'domnode::c14nfile' => ['uri', 'exclusive=', 'withComments=', 'xpath=', 'nsPrefixes='],
            'domnode::clonenode' => ['deep='],
            'domnode::insertbefore' => ['node', 'child='],
            'domnode::isdefaultnamespace' => ['namespace'],
            'domnode::issamenode' => ['otherNode'],
            'domnode::issupported' => ['feature', 'version'],
            'domnode::lookupnamespaceuri' => ['prefix'],
            'domnode::lookupprefix' => ['namespace'],
            'domnode::removechild' => ['child'],
            'domnode::replacechild' => ['node', 'child'],
            'domnodelist::item' => ['index'],
            'domnamednodemap::item' => ['index'],
            'domxpath::__construct' => ['document', 'registerNodeNS='],
            'domxpath::evaluate' => ['expression', 'contextNode=', 'registerNodeNS='],
            'domxpath::query' => ['expression', 'contextNode=', 'registerNodeNS='],
            'domxpath::registernamespace' => ['prefix', 'namespace'],
            'domxpath::registerphpfunctions' => ['restrict='],
            // php-src ext/simplexml/simplexml.stub.php (#23686)
            'simplexmlelement::xpath' => ['expression'],
            'simplexmlelement::registerxpathnamespace' => ['prefix', 'namespace'],
            'simplexmlelement::asxml' => ['filename'],
            'simplexmlelement::addchild' => ['qualifiedName', 'value', 'namespace'],
            'simplexmlelement::addattribute' => ['qualifiedName', 'value', 'namespace'],
            'simplexmlelement::getnamespaces' => ['recursive'],
            'simplexmlelement::getdocnamespaces' => ['recursive', 'fromRoot'],
            // php-src ext/xmlreader/php_xmlreader.stub.php (#23391, #28712)
            'xmlreader::expand' => ['baseNode='],
            'xmlreader::getattributens' => ['name', 'namespace'],
            'xmlreader::movetoattributens' => ['name', 'namespace'],
            'xmlreader::next' => ['name'],
            // encoding/?string=null, flags/int=0 — InternalArgInfo still has non-nullable encoding (#28712)
            'xmlreader::open' => ['uri', 'encoding=', 'flags='],
            'xmlreader::xml' => ['source', 'encoding=', 'flags='],
            // php-src ext/xmlreader/php_xmlreader.stub.php — PHP 8.4+ factories (#27713)
            'xmlreader::fromstring' => ['source', 'encoding=', 'flags='],
            'xmlreader::fromuri' => ['uri', 'encoding=', 'flags='],
            'xmlreader::fromstream' => ['stream', 'encoding=', 'flags=', 'documentUri='],
            // php-src ext/xmlwriter/php_xmlwriter.stub.php — InternalArgInfo still has pre-stub names (#23407)
            'xmlwriter::setindent' => ['enable'],
            'xmlwriter::setindentstring' => ['indentation'],
            'xmlwriter::flush' => ['empty'],
            'xmlwriter::outputmemory' => ['flush'],
            'xmlwriter::startattributens' => ['prefix', 'name', 'namespace'],
            'xmlwriter::writeattributens' => ['prefix', 'name', 'namespace', 'value'],
            'xmlwriter::startelementns' => ['prefix', 'name', 'namespace'],
            'xmlwriter::writeelementns' => ['prefix', 'name', 'namespace', 'content'],
            // php-src ext/xmlwriter/php_xmlwriter.stub.php — PHP 8.4+ factories (#27922)
            'xmlwriter::tomemory' => [],
            'xmlwriter::touri' => ['uri'],
            'xmlwriter::tostream' => ['stream'],
            // php-src ext/fileinfo/fileinfo.stub.php — methods missing from InternalArgInfo (#23410)
            'finfo::buffer' => ['string', 'flags=', 'context='],
            'finfo::file' => ['filename', 'flags=', 'context='],
            'finfo::set_flags' => ['flags'],
            // php-src ext/fileinfo/fileinfo.stub.php — InternalArgInfo still says options/magic_file (#26181)
            'finfo::__construct' => ['flags=', 'magic_database='],
            // php-src ext/bcmath/bcmath.stub.php — InternalArgInfo empty (#24626)
            'bcmath\\number::__construct' => ['num'],
            // php-src Zend/zend_fibers.stub.php — InternalArgInfo empty (#24592)
            'fiber::__construct' => ['callback'],
            // php-src Zend/zend_weakrefs.stub.php — InternalArgInfo empty (#24592)
            'weakreference::create' => ['object'],
            // php-src Zend/zend_closures.stub.php — InternalArgInfo still says old/to/scope (#24591)
            'closure::bind' => ['closure', 'newThis', 'newScope='],
            'closure::bindto' => ['newThis', 'newScope='],
            'closure::call' => ['newThis', '...args'],
            default => null,
        };
    }

    public static function forFunction(string $name): ?array
    {
        $classMethod = self::forClassMethod($name);
        if (null !== $classMethod) {
            return $classMethod;
        }

        $lc = strtolower($name);
        switch ($lc) {
            case 'intltz_get_iana_id':
                return ['zoneId'];
            case 'strlen':
            case 'ucfirst':
            case 'lcfirst':
            case 'strtoupper':
            case 'strtolower':
            case 'addslashes':
            case 'stripslashes':
            // php-src ext/standard/string.stub.php — InternalArgInfo still says str (#24865)
            case 'stripcslashes':
            case 'quoted_printable_encode':
            case 'quoted_printable_decode':
            case 'bin2hex':
            // php-src ext/standard/basic_functions.stub.php — InternalArgInfo still says str (#23264)
            case 'quotemeta':
            case 'strrev':
            case 'str_rot13':
                return ['string'];
            // php-src ext/standard/basic_functions.stub.php — InternalArgInfo still says str/salt (#23264)
            case 'crypt':
                return ['string', 'salt'];
            // php-src ext/standard/basic_functions.stub.php — InternalArgInfo still says str/crc (#23491)
            case 'crc32':
                return ['string'];
            // php-src ext/standard/string.stub.php — InternalArgInfo still says str1/str2/len (#23335)
            case 'strcmp':
            case 'strcasecmp':
            // php-src stub string1/string2; InternalArgInfo still says s1/s2 (#24866)
            case 'strnatcmp':
            case 'strnatcasecmp':
                return ['string1', 'string2'];
            case 'strncmp':
            case 'strncasecmp':
                return ['string1', 'string2', 'length'];
            // php-src ext/standard/string.stub.php — arity 3; no $truncate (#25749, #27749)
            case 'substr':
                return ['string', 'offset', 'length='];
            // php-src ext/standard/string.stub.php — InternalArgInfo still says cut (#23191)
            case 'wordwrap':
                return ['string', 'width', 'break', 'cut_long_words'];
            case 'date':
            case 'gmdate':
                // php-src ext/date/php_date.stub.php — ?int $timestamp = null (#24845)
                return ['format', 'timestamp='];
            // php-src ext/date/php_date.stub.php — string $format, ?int $timestamp = null (#25440)
            case 'idate':
                return ['format', 'timestamp='];
            // php-src ext/date/php_date.stub.php — Reflection OK but BuiltinParamNames missing (#23462)
            case 'checkdate':
                return ['month', 'day', 'year'];
            // php-src ext/calendar/calendar.stub.php — InternalArgInfo still says jday (#24509)
            case 'jdtounix':
                return ['julian_day'];
            case 'easter_date':
                return ['year=', 'mode='];
            // php-src ext/calendar/calendar.stub.php — ?int $timestamp = null (#24863)
            case 'unixtojd':
                return ['timestamp='];
            // php-src ext/calendar/calendar.stub.php — pre-stub InternalArgInfo names (#24362)
            case 'cal_from_jd':
                return ['julian_day', 'calendar'];
            case 'easter_days':
                return ['year=', 'mode='];
            case 'jdtogregorian':
            case 'jdtojulian':
            case 'jdtofrench':
                return ['julian_day'];
            case 'jdtojewish':
                return ['julian_day', 'hebrew=', 'flags='];
            case 'jddayofweek':
                return ['julian_day', 'mode='];
            case 'jdmonthname':
                return ['julian_day', 'mode'];
            case 'getdate':
                // php-src ext/date/php_date.stub.php — ?int $timestamp = null (#25440)
                return ['timestamp='];
            // php-src ext/date/php_date.stub.php — InternalArgInfo still says date (#23783)
            case 'date_parse':
                return ['datetime'];
            case 'date_parse_from_format':
                return ['format', 'datetime'];
            // php-src ext/date/php_date.stub.php — ?int $baseTimestamp = null (#23216 / #24845)
            case 'strtotime':
                return ['datetime', 'baseTimestamp='];
            // php-src ext/date/php_date.stub.php — Reflection empty / InternalArgInfo absent (#23276 / #25392)
            case 'date_create':
            case 'date_create_immutable':
                return ['datetime=', 'timezone='];
            // php-src ext/date/php_date.stub.php — Reflection empty / InternalArgInfo pre-stub (#23289)
            case 'date_create_from_format':
            case 'date_create_immutable_from_format':
                // php-src php_date.stub.php — ?DateTimeZone $timezone = null (#27773)
                return ['format', 'datetime', 'timezone='];
            case 'date_modify':
                return ['object', 'modifier'];
            case 'date_add':
            case 'date_sub':
                return ['object', 'interval'];
            case 'date_date_set':
                return ['object', 'year', 'month', 'day'];
            case 'date_time_set':
                // php-src ext/date/php_date.stub.php — second/microsecond optional (#25400)
                return ['object', 'hour', 'minute', 'second=', 'microsecond='];
            case 'date_timestamp_set':
                return ['object', 'timestamp'];
            case 'date_timezone_set':
                return ['object', 'timezone'];
            case 'date_isodate_set':
                return ['object', 'year', 'week', 'dayOfWeek'];
            case 'date_interval_create_from_date_string':
                return ['datetime'];
            case 'date_interval_format':
                return ['object', 'format'];
            case 'date_diff':
                return ['baseObject', 'targetObject', 'absolute'];
            case 'date_format':
                return ['object', 'format'];
            case 'date_offset_get':
            case 'date_timestamp_get':
            case 'date_timezone_get':
                return ['object'];
            // php-src ext/date/php_date.stub.php — InternalArgInfo still says timezone_identifier (#23446)
            case 'date_default_timezone_set':
                return ['timezoneId'];
            // php-src ext/date/php_date.stub.php — InternalArgInfo required what/country (#23446, #25173)
            case 'timezone_identifiers_list':
                return ['timezoneGroup=', 'countryCode='];
            // php-src ext/date/php_date.stub.php — InternalArgInfo still gmtoffset/isdst (#24359)
            case 'timezone_name_from_abbr':
                return ['abbr', 'utcOffset=', 'isDST='];
            // php-src ext/date/php_date.stub.php — procedural wrappers; transitions arg order/names (#24360)
            case 'timezone_location_get':
                return ['object'];
            case 'timezone_offset_get':
                return ['object', 'datetime'];
            case 'timezone_transitions_get':
                return ['object', 'timestampBegin=', 'timestampEnd='];
            // php-src ext/date/php_date.stub.php — InternalArgInfo still time/format/gmt_offset (#24363)
            case 'date_sun_info':
                return ['timestamp', 'latitude', 'longitude'];
            case 'date_sunrise':
            case 'date_sunset':
                return ['timestamp', 'returnFormat=', 'latitude=', 'longitude=', 'zenith=', 'utcOffset='];
            // php-src ext/date/php_date.stub.php — InternalArgInfo still min/sec/mon; hour required (#23275, #25147)
            case 'mktime':
            case 'gmmktime':
                return ['hour', 'minute=', 'second=', 'month=', 'day=', 'year='];
            // php-src ext/date/php_date.stub.php — associative_array→associative (#23447)
            case 'localtime':
                return ['timestamp=', 'associative='];
            // php-src ext/date/php_date.stub.php — ?int $timestamp = null (#27981)
            case 'strftime':
            case 'gmstrftime':
                return ['format', 'timestamp='];
            // php-src ext/standard/basic_functions.stub.php — exactly array+callback (#23875).
            // #6949 asked for optional $strict; php-src never shipped it.
            case 'array_all':
            case 'array_any':
            case 'array_find':
            case 'array_find_key':
                return ['array', 'callback'];
            case 'str_pad':
                return ['string', 'length', 'pad_string', 'pad_type'];
            case 'str_replace':
            case 'str_ireplace':
                // php-src ext/standard/string.stub.php — &$count = null (#24886)
                return ['search', 'replace', 'subject', 'count='];
            case 'parse_str':
                // php-src basic_functions.stub.php — arity 2 only (#23949; reverts #17320 phantom)
                return ['string', 'result'];
            case 'mb_parse_str':
                return ['string', 'result'];
            case 'dns_get_mx':
            case 'getmxrr':
                // php-src basic_functions.stub.php — hosts/weights (InternalArgInfo mxhosts/weight) (#23353)
                return ['hostname', 'hosts', 'weights='];
            // php-src basic_functions.stub.php — protocol (InternalArgInfo name/proto) (#24562)
            case 'getprotobyname':
            case 'getprotobynumber':
                return ['protocol'];
            // php-src ext/standard/basic_functions.stub.php — host→hostname (#23358)
            case 'checkdnsrr':
            case 'dns_check_record':
                return ['hostname', 'type='];
            // php-src basic_functions.stub.php — authns/addtl→authoritative_*/additional_*; +raw (#23358)
            case 'dns_get_record':
                return [
                    'hostname',
                    'type=',
                    'authoritative_name_servers=',
                    'additional_records=',
                    'raw=',
                ];
            case 'gethostbyname':
                // php-src ext/standard/basic_functions.stub.php / dns.c (#23492)
                return ['hostname'];
            // php-src ext/standard/basic_functions.stub.php — InternalArgInfo still says ip_address/in_addr (#28916)
            case 'inet_pton':
            case 'inet_ntop':
                return ['ip'];
            // php-src ext/ftp/ftp.stub.php — InternalArgInfo still says host (#23644)
            case 'ftp_connect':
            case 'ftp_ssl_connect':
                // php-src ext/ftp/ftp.stub.php — port=21, timeout=90 (#28570)
                return ['hostname', 'port=', 'timeout='];
            // php-src ext/ftp/ftp.stub.php — InternalArgInfo still says stream/local_file/… (#23656, #24639)
            case 'ftp_login':
                return ['ftp', 'username', 'password'];
            case 'ftp_pwd':
            case 'ftp_cdup':
            case 'ftp_systype':
            case 'ftp_nb_continue':
            case 'ftp_close':
            case 'ftp_quit':
                return ['ftp'];
            case 'ftp_chdir':
            case 'ftp_mkdir':
            case 'ftp_rmdir':
            case 'ftp_nlist':
            case 'ftp_mlsd':
                return ['ftp', 'directory'];
            case 'ftp_rawlist':
                return ['ftp', 'directory', 'recursive'];
            case 'ftp_exec':
            case 'ftp_raw':
            case 'ftp_site':
                return ['ftp', 'command'];
            case 'ftp_chmod':
                return ['ftp', 'permissions', 'filename'];
            case 'ftp_alloc':
                return ['ftp', 'size', '&response='];
            case 'ftp_pasv':
                return ['ftp', 'enable'];
            case 'ftp_size':
            case 'ftp_mdtm':
            case 'ftp_delete':
                return ['ftp', 'filename'];
            case 'ftp_rename':
                return ['ftp', 'from', 'to'];
            case 'ftp_get_option':
                return ['ftp', 'option'];
            case 'ftp_set_option':
                return ['ftp', 'option', 'value'];
            case 'ftp_get':
            case 'ftp_nb_get':
                // php-src ext/ftp/ftp.stub.php — mode=FTP_BINARY, offset=0 (#28570)
                return ['ftp', 'local_filename', 'remote_filename', 'mode=', 'offset='];
            case 'ftp_put':
            case 'ftp_nb_put':
                return ['ftp', 'remote_filename', 'local_filename', 'mode=', 'offset='];
            case 'ftp_append':
                // php-src ext/ftp/ftp.stub.php — int $mode = FTP_BINARY (#27686)
                return ['ftp', 'remote_filename', 'local_filename', 'mode='];
            case 'ftp_fget':
            case 'ftp_nb_fget':
                return ['ftp', 'stream', 'remote_filename', 'mode', 'offset'];
            case 'ftp_fput':
            case 'ftp_nb_fput':
                return ['ftp', 'remote_filename', 'stream', 'mode', 'offset'];
            case 'sort':
            case 'rsort':
                // php-src ext/standard/basic_functions.stub.php — array, flags only (#23225).
                // SortDirection enum (PHP 8.6) is not a sort()/rsort() parameter.
                return ['array', 'flags'];
            case 'asort':
            case 'arsort':
            case 'ksort':
            case 'krsort':
                return ['array', 'flags'];
            // php-src basic_functions.stub.php — natsort/natcasesort take only array (&$array); no $flags (#23243)
            case 'natsort':
            case 'natcasesort':
                return ['array'];
            case 'usort':
            case 'uasort':
            case 'uksort':
                // php-src basic_functions.stub.php — array &$array, callable $callback only.
                // SortDirection (PHP 8.6 enum) is not a usort parameter (#23385, #26142).
                return ['array', 'callback'];
            case 'shuffle':
            case 'array_sum':
            case 'array_product':
                return ['array'];
            // php-src ext/standard/array.stub.php — PHP 8.5+ array_first/array_last (#23895)
            case 'array_first':
            case 'array_last':
                return ['array'];
            // php-src ext/standard/basic_functions.stub.php — array $array; InternalArgInfo empty (#23262)
            case 'array_is_list':
            case 'array_key_first':
            case 'array_key_last':
                return ['array'];
            // php-src ext/standard/array.stub.php — array $array, int $case = CASE_LOWER; InternalArgInfo still says input (#25500)
            case 'array_change_key_case':
                return ['array', 'case='];
            // php-src ext/standard/basic_functions.stub.php — InternalArgInfo still says input/search_value/sort_flags (#23274)
            case 'array_keys':
                return ['array', 'filter_value=', 'strict='];
            case 'array_values':
            case 'array_flip':
                return ['array'];
            case 'array_unique':
                return ['array', 'flags='];
            // php-src ext/standard/array.stub.php — start/end; step=1 (InternalArgInfo still says low/high) (#23242 / #25070)
            case 'range':
                return ['start', 'end', 'step='];
            case 'array_push':
                return ['array', 'values'];
            case 'array_unshift':
                return ['array', 'values'];
            case 'array_pop':
            case 'array_shift':
            case 'current':
            case 'end':
            case 'key':
            case 'next':
            case 'prev':
            case 'reset':
                return ['array'];
            // php-src ext/standard/array.stub.php — array $array, array ...$replacements (variadic optional) (#25480)
            case 'array_replace':
            case 'array_replace_recursive':
                return ['array', '...replacements'];
            // php-src ext/standard/array.stub.php — array $array, array ...$rest (callback in rest) (#23959)
            case 'array_udiff':
            case 'array_udiff_assoc':
            case 'array_udiff_uassoc':
            case 'array_uintersect':
            case 'array_uintersect_assoc':
            case 'array_uintersect_uassoc':
                return ['array', '...rest'];
            case 'array_walk':
            case 'array_walk_recursive':
                return ['array', 'callback', 'arg'];
            case 'array_slice':
                return ['array', 'offset', 'length', 'preserve_keys'];
            // php-src ext/standard/array.stub.php — ?int $length = null, mixed $replacement = [] (#24824)
            case 'array_splice':
                return ['array', 'offset', 'length=', 'replacement='];
            case 'array_multisort':
                return ['array', 'rest'];
            case 'array_map':
                return ['callback', 'array', 'arrays'];
            // php-src ext/standard/array.stub.php — ?callable $callback = null, int $mode = 0 (#24843)
            case 'array_filter':
                return ['array', 'callback=', 'mode='];
            case 'array_reduce':
                return ['array', 'callback', 'initial'];
            // php-src ext/standard/basic_functions.stub.php — arity 3; no $pad_type (#24002)
            case 'array_pad':
                return ['array', 'length', 'value'];
            case 'array_combine':
                return ['keys', 'values'];
            // php-src ext/standard/basic_functions.stub.php — InternalArgInfo still says keys/val (#23490)
            case 'array_fill_keys':
                return ['keys', 'value'];
            // php-src ext/standard/basic_functions.stub.php — InternalArgInfo still says start_key/num/val (#23305)
            case 'array_fill':
                return ['start_index', 'count', 'value'];
            // php-src ext/standard/basic_functions.stub.php — InternalArgInfo still says input/preserve (#23305)
            case 'array_reverse':
                return ['array', 'preserve_keys='];
            case 'array_chunk':
                return ['array', 'length', 'preserve_keys'];
            case 'similar_text':
                return ['string1', 'string2', 'percent'];
            // php-src ext/standard/string.stub.php — int costs default 1 (#24791)
            case 'levenshtein':
                return ['string1', 'string2', 'insertion_cost=', 'replacement_cost=', 'deletion_cost='];
            case 'settype':
                return ['var', 'type'];
            case 'register_shutdown_function':
                // php-src ext/standard/basic_functions.stub.php — callable $callback, mixed ...$args (#23380)
                return ['callback', 'args'];
            case 'header':
                return ['header', 'replace', 'response_code'];
            case 'header_register_callback':
                return ['callback'];
            case 'headers_sent':
                return ['filename', 'line'];
            case 'number_format':
                return ['num', 'decimals', 'decimal_separator', 'thousands_separator'];
            // php-src ext/standard/basic_functions.stub.php — InternalArgInfo still says number (#23259)
            case 'abs':
            case 'floor':
            case 'ceil':
                return ['num'];
            // php-src ext/standard/basic_functions.stub.php — InternalArgInfo still base/number/x (#23306)
            case 'pow':
                return ['num', 'exponent'];
            case 'sqrt':
                return ['num'];
            case 'fmod':
                return ['num1', 'num2'];
            case 'round':
                return ['num', 'precision', 'mode'];
            case 'frexp':
                return ['arg1', 'exp'];
            case 'clearstatcache':
                return ['clear_realpath_cache', 'filename'];
            // php-src ext/standard/filestat.stub.php — InternalArgInfo still says mode (#23346)
            case 'chmod':
                return ['filename', 'permissions'];
            case 'mkdir':
                // php-src ext/standard/file.stub.php — permissions=0777, recursive=false, context=null (#23453 / #24885)
                return ['directory', 'permissions=', 'recursive=', 'context='];
            case 'rmdir':
                // php-src ext/standard/basic_functions.stub.php / filestat.c (#23454)
                // Override InternalArgInfo dirname → Zend directory
                return ['directory', 'context'];
            // php-src file.stub.php / dir.stub.php / basic_functions.stub.php (#23461)
            case 'unlink':
                return ['filename', 'context'];
            // php-src ext/standard/basic_functions.stub.php — InternalArgInfo still says path/new_path (#28854)
            case 'move_uploaded_file':
                return ['from', 'to'];
            // php-src ext/standard/file.stub.php — directory/prefix (InternalArgInfo dir) (#23459)
            case 'tempnam':
                return ['directory', 'prefix'];
            // php-src ext/standard/dir.stub.php — directory (InternalArgInfo dir) (#23448)
            case 'scandir':
                return ['directory', 'sorting_order=', 'context='];
            // php-src ext/standard/dir.stub.php — directory (InternalArgInfo path) (#26320)
            case 'opendir':
                return ['directory', 'context='];
            case 'chdir':
                return ['directory'];
            // php-src ext/standard/basic_functions.stub.php — ?int $mask = null (#24971)
            case 'umask':
                return ['mask='];
            case 'fnmatch':
                return ['pattern', 'filename', 'flags'];
            case 'sem_get':
                // php-src ext/sysvsem/sysvsem.stub.php (#19515)
                return ['key', 'max_acquire', 'permissions', 'auto_release'];
            // php-src ext/sysvsem/sysvsem.stub.php — InternalArgInfo still says id (#24610)
            case 'sem_acquire':
                return ['semaphore', 'non_blocking='];
            case 'sem_release':
            case 'sem_remove':
                return ['semaphore'];
            // php-src ext/shmop/shmop.stub.php — InternalArgInfo still says flags/shmid/start/count (#24391)
            case 'shmop_open':
                return ['key', 'mode', 'permissions', 'size'];
            case 'shmop_read':
                return ['shmop', 'offset', 'size'];
            case 'shmop_write':
                return ['shmop', 'data', 'offset'];
            case 'shmop_size':
            case 'shmop_close':
            case 'shmop_delete':
                return ['shmop'];
            // php-src ext/sysvshm/sysvshm.stub.php — InternalArgInfo still memsize/perm/shm_identifier (#24640)
            case 'shm_attach':
                return ['key', 'size=', 'permissions='];
            case 'shm_detach':
            case 'shm_remove':
                return ['shm'];
            case 'shm_put_var':
                return ['shm', 'key', 'value'];
            case 'shm_get_var':
            case 'shm_has_var':
            case 'shm_remove_var':
                return ['shm', 'key'];
            // php-src ext/mysqli/mysqli.stub.php — InternalArgInfo still link/resultmode/escapestr (#24664)
            case 'mysqli_query':
                return ['mysql', 'query', 'result_mode='];
            case 'mysqli_prepare':
                return ['mysql', 'query'];
            case 'mysqli_real_escape_string':
                return ['mysql', 'string'];
            // php-src ext/mysqli/mysqli.stub.php — absent from InternalArgInfo (#27712)
            case 'mysqli_execute_query':
                return ['mysql', 'query', 'params='];
            // php-src ext/standard/basic_functions.stub.php — InternalArgInfo still pathname/proj (#26117)
            case 'ftok':
                return ['filename', 'project_id'];
            case 'msg_get_queue':
                // php-src ext/sysvmsg/sysvmsg.stub.php (#3666)
                return ['key', 'permissions'];
            case 'msg_send':
                return ['queue', 'message_type', 'message', 'serialize', 'blocking', 'error_code'];
            case 'msg_receive':
                return [
                    'queue',
                    'desired_message_type',
                    'received_message_type',
                    'max_message_size',
                    'message',
                    'unserialize',
                    'flags',
                    'error_code',
                ];
            case 'msg_remove_queue':
            case 'msg_stat_queue':
                return ['queue'];
            case 'msg_queue_exists':
                return ['key'];
            case 'spl_autoload_register':
                // php-src ext/spl/spl.stub.php — ?callable=null, throw=true, prepend=false (#25390)
                // InternalArgInfo still says autoload_function + bool infer defaults throw to false.
                return ['callback=', 'throw=', 'prepend='];
            // php-src ext/spl/spl.stub.php — InternalArgInfo still says autoload_function (#23680)
            case 'spl_autoload_unregister':
                return ['callback'];
            // php-src ext/spl/spl.stub.php — InternalArgInfo still says obj / empty (#24569)
            case 'spl_object_hash':
            case 'spl_object_id':
                return ['object'];
            // php-src ext/standard/basic_functions.stub.php — PHP 8.3+; InternalArgInfo omits (#26210)
            case 'get_object_id':
                return ['object'];
            // php-src ext/standard/file.stub.php — ?int $mtime = null, ?int $atime = null (#24971)
            case 'touch':
                return ['filename', 'mtime=', 'atime='];
            // php-src ext/tokenizer/tokenizer.stub.php — int $flags = 0; InternalArgInfo omits flags (#26258)
            case 'token_get_all':
                return ['code', 'flags='];
            // php-src ext/tokenizer/tokenizer.stub.php — InternalArgInfo still says type (#23658)
            case 'token_name':
                return ['id'];
            // php-src ext/standard/basic_functions.stub.php — ?string $name = null, bool $local_only = false
            // InternalArgInfo still says varname / single required string (#24855)
            case 'getenv':
                return ['name=', 'local_only='];
            // php-src ext/standard/basic_functions.stub.php — InternalArgInfo still says setting (#23258)
            case 'putenv':
                return ['assignment'];
            case 'ini_get':
                return ['option'];
            // php-src ext/standard/basic_functions.stub.php — InternalArgInfo still says option_name (#23569)
            case 'get_cfg_var':
                return ['option'];
            // php-src ext/standard/basic_functions.stub.php — InternalArgInfo still says extension_name (#23569)
            case 'get_extension_funcs':
                return ['extension'];
            // php-src ext/standard/basic_functions.stub.php — InternalArgInfo still says arg (#23569)
            case 'cli_set_process_title':
                return ['title'];
            // php-src ext/standard/basic_functions.stub.php — ?string $extension = null, bool $details = true (#25276)
            case 'ini_get_all':
                return ['extension=', 'details='];
            case 'ini_set':
            case 'ini_alter':
                // php-src basic_functions.stub.php — ini_alter is PHP_FALIAS of ini_set (#6085, #26465)
                return ['option', 'value'];
            // php-src ext/standard/basic_functions.stub.php — InternalArgInfo still says varname (#23568)
            case 'ini_restore':
                return ['option'];
            // php-src ext/standard/basic_functions.stub.php — InternalArgInfo still says value (#23568)
            case 'ignore_user_abort':
                return ['enable='];
            // php-src ext/standard/basic_functions.stub.php — InternalArgInfo still says new_include_path (#23568)
            case 'set_include_path':
                return ['include_path'];
            // php-src ext/standard/basic_functions.stub.php — InternalArgInfo still says new_error_level (#23436)
            case 'error_reporting':
                return ['error_level'];
            // php-src ext/standard/basic_functions.stub.php / exec.c (#23460)
            case 'escapeshellarg':
                return ['arg'];
            case 'escapeshellcmd':
                return ['command'];
            // php-src ext/session/session.stub.php — ?string $name = null; InternalArgInfo still says newname (#23436, #31423)
            case 'session_name':
                return ['name='];
            // php-src ext/session/session.stub.php — ?string $id = null; InternalArgInfo still says newid (#23402, #26460)
            case 'session_id':
                return ['id='];
            // php-src ext/session/session.stub.php — string $prefix = ""; InternalArgInfo required (#27725)
            case 'session_create_id':
                return ['prefix='];
            // php-src ext/session/session.stub.php — open required; close…update_timestamp optional (#23958)
            case 'session_set_save_handler':
                return [
                    'open',
                    'close=',
                    'read=',
                    'write=',
                    'destroy=',
                    'gc=',
                    'create_sid=',
                    'validate_sid=',
                    'update_timestamp=',
                ];
            // php-src Zend/zend_builtin_functions.stub.php — InternalArgInfo still says error_type (#23402)
            case 'trigger_error':
                return ['message', 'error_level'];
            // user_error is absent from InternalArgInfo — encode optionality here (#25174)
            case 'user_error':
                return ['message', 'error_level='];
            // php-src Zend/zend_builtin_functions.stub.php — InternalArgInfo still says arg_num (#24456)
            case 'func_get_arg':
                return ['position'];
            // php-src ext/session/session.stub.php — InternalArgInfo still new_cache_* (#24583)
            case 'session_cache_limiter':
                return ['value='];
            case 'session_cache_expire':
                return ['value='];
            // php-src ext/session/session.stub.php — lifetime → lifetime_or_options (#23846 / #24533)
            // php-src ext/session/session.stub.php — path/domain/secure/httponly = null (#24971)
            case 'session_set_cookie_params':
                return ['lifetime_or_options', 'path=', 'domain=', 'secure=', 'httponly='];
            case 'define':
                return ['constant_name', 'value', 'case_insensitive'];
            // php-src Zend/zend_builtin_functions.stub.php — InternalArgInfo still says const_name/name (#23434)
            case 'constant':
                return ['name'];
            case 'defined':
                return ['constant_name'];
            case 'vsprintf':
                return ['format', 'args'];
            case 'sprintf':
            case 'printf':
                // Zend stub: format + ...values (#22825); arity via zendInternalVariadicReflectionArity.
                return ['format', 'values'];
            case 'sscanf':
                return ['string', 'format', 'vars'];
            case 'vfscanf':
            case 'fscanf':
                return ['stream', 'format', 'vars'];
            case 'fprintf':
                return ['stream', 'format', 'values'];
            // php-src ext/standard/basic_functions.stub.php — InternalArgInfo still says args (#24535)
            case 'vfprintf':
                return ['stream', 'format', 'values'];
            case 'pack':
                return ['format', 'values'];
            // php-src ext/pgsql/pgsql.stub.php — InternalArgInfo still field_number (#27703)
            case 'pg_field_table':
                return ['result', 'field', 'oid_only='];
            // php-src ext/pgsql/pgsql.stub.php — InternalArgInfo garbled connect_type]/port/… (#27811)
            case 'pg_connect':
            case 'pg_pconnect':
                return ['connection_string', 'flags='];
            // php-src ext/standard/array.stub.php — array ...$arrays (0 required); InternalArgInfo still arr1+... (#25382)
            case 'array_merge':
            case 'array_merge_recursive':
                return ['...arrays'];
            case 'var_dump':
            case 'debug_zval_dump':
            case 'max':
            case 'min':
                return ['value', 'values'];
            // php-src ext/standard/basic_functions.stub.php — InternalArgInfo still variable / variable_representation+allowed_classes (#23260)
            case 'serialize':
                return ['value'];
            case 'unserialize':
                return ['data', 'options='];
            // php-src ext/standard/basic_functions.stub.php — InternalArgInfo var_names + phantom ... (#23803)
            case 'compact':
                return ['var_name', 'var_names'];
            case 'fread':
                return ['stream', 'length'];
            case 'fwrite':
            case 'fputs':
                return ['stream', 'data', 'length'];
            // php-src ext/standard/file.stub.php — InternalArgInfo still says fp (#23241)
            case 'fclose':
            case 'feof':
            case 'fgetc':
            case 'ftell':
            case 'rewind':
            case 'fflush':
            // php-src ext/standard/file.stub.php — absent from InternalArgInfo (#23406)
            case 'fsync':
            case 'fdatasync':
                return ['stream'];
            // php-src ext/standard/file.stub.php — InternalArgInfo still says fp (#24534)
            case 'ftruncate':
                return ['stream', 'size'];
            case 'fseek':
                return ['stream', 'offset', 'whence'];
            case 'socket_select':
                return ['read', 'write', 'except', 'seconds', 'microseconds'];
            // php-src ext/sockets/sockets.stub.php — InternalArgInfo still says addr/buf/type/optname (#24373)
            case 'socket_bind':
            case 'socket_connect':
                return ['socket', 'address', 'port='];
            case 'socket_read':
                return ['socket', 'length', 'mode='];
            case 'socket_write':
                return ['socket', 'data', 'length='];
            case 'socket_set_option':
            case 'socket_setopt':
                return ['socket', 'level', 'option', 'value'];
            // php-src ext/sockets/sockets.stub.php — missing from InternalArgInfo (#25133)
            case 'socket_export_stream':
                return ['socket'];
            case 'socket_import_stream':
                return ['stream'];
            // php-src ext/sockets/sockets.stub.php — InternalArgInfo still says errno (#24642)
            case 'socket_strerror':
                return ['error_code'];
            // php-src ext/standard/basic_functions.stub.php — InternalArgInfo still says read_streams/tv_sec (#23598)
            case 'stream_select':
                return ['read', 'write', 'except', 'seconds', 'microseconds'];
            // php-src ext/standard/file.stub.php — separator/enclosure/escape/eol optional (#25135, #25259)
            // Trailing `eol=` alone made namesEncodeOptionalParams=true and forced mid-params required.
            case 'fputcsv':
                return ['stream', 'fields', 'separator=', 'enclosure=', 'escape=', 'eol='];
            case 'stream_context_create':
                return ['options', 'params'];
            case 'stream_copy_to_stream':
                return ['from', 'to', 'length', 'offset'];
            case 'stream_socket_client':
                return ['address', 'error_code', 'error_message', 'timeout', 'flags', 'context'];
            // php-src ext/standard/basic_functions.stub.php — InternalArgInfo still says errno/errstr (#28919)
            case 'fsockopen':
            case 'pfsockopen':
                return ['hostname', 'port', 'error_code', 'error_message', 'timeout'];
            // php-src ext/standard/streamsfuncs.stub.php — InternalArgInfo still says localaddress/errcode/errstring (#23937)
            case 'stream_socket_server':
                return ['address', 'error_code', 'error_message', 'flags', 'context'];
            // php-src streamsfuncs.stub.php — InternalArgInfo still says serverstream/peername (#23938)
            case 'stream_socket_accept':
                return ['socket', 'timeout', 'peer_name'];
            // php-src streamsfuncs.stub.php — InternalArgInfo still says stream/want_peer (#23938)
            case 'stream_socket_get_name':
                return ['socket', 'remote'];
            // php-src streamsfuncs.stub.php — InternalArgInfo cryptokind/sessionstream (#27684)
            case 'stream_socket_enable_crypto':
                return ['stream', 'enable', 'crypto_method=', 'session_stream='];
            // php-src streamsfuncs.stub.php — InternalArgInfo still says fp/buffer (#23939)
            case 'stream_set_write_buffer':
                return ['stream', 'size'];
            // php-src stream context stubs — InternalArgInfo still says wrappername/optionname (#23939)
            // option_name=null / value=UNKNOWN optional; `=` encodes required=2 (#25845)
            case 'stream_context_set_option':
                return ['context', 'wrapper_or_options', 'option_name=', 'value='];
            // php-src ext/standard/basic_functions.stub.php — PHP 8.4; absent from InternalArgInfo (#25453)
            case 'stream_context_set_options':
                return ['context', 'options'];
            // php-src stream context stubs — InternalArgInfo still says options (#23939)
            case 'stream_context_set_params':
                return ['context', 'params'];
            // php-src ext/standard/streamsfuncs.stub.php — InternalArgInfo still says classname (#24488)
            case 'stream_wrapper_register':
                return ['protocol', 'class', 'flags'];
            case 'stream_filter_register':
                return ['filter_name', 'class'];
            // php-src ext/standard/basic_functions.stub.php — InternalArgInfo filtername/read_write/filterparams (#28908)
            case 'stream_filter_append':
            case 'stream_filter_prepend':
                return ['stream', 'filter_name', 'mode=', 'params='];
            // php-src ext/standard/basic_functions.stub.php — InternalArgInfo still says context (#24584)
            case 'stream_context_get_options':
                return ['stream_or_context'];
            // php-src ext/standard/basic_functions.stub.php — associative=false, context=null (#23598, #25780)
            // InternalArgInfo still says format=; omits context optionality.
            case 'get_headers':
                return ['url', 'associative=', 'context='];
            case 'flock':
                // php-src ext/standard/file.stub.php — ?bool &$would_block = null (#23352)
                return ['stream', 'operation', '&would_block='];
            case 'get_resources':
                return ['resource_type'];
            // php-src ext/standard/basic_functions.stub.php — InternalArgInfo still says res (#23342)
            case 'get_resource_type':
                return ['resource'];
            // php-src ext/standard/basic_functions.stub.php — PHP builtin; no InternalArgInfo (#24489)
            case 'get_resource_id':
                return ['resource'];
            // php-src ext/standard/basic_functions.stub.php — PHP builtin; no InternalArgInfo (#24609)
            case 'stream_isatty':
                return ['stream'];
            // php-src ext/standard/basic_functions.stub.php — InternalArgInfo still says fp (#23658)
            case 'stream_get_meta_data':
            case 'socket_get_status': // PHP_FALIAS
                return ['stream'];
            // php-src ext/standard/basic_functions.stub.php — InternalArgInfo still says socket/mode (#23658)
            case 'stream_set_blocking':
            case 'socket_set_blocking': // PHP_FALIAS
                return ['stream', 'enable'];
            case 'get_browser':
                return ['browser_name', 'return_array'];
            case 'get_defined_constants':
                // php-src Zend/zend_builtin_functions.stub.php — arity 1 on every version (#28522).
                return ['categorize'];
            case 'get_declared_classes':
            case 'get_declared_interfaces':
            case 'get_declared_traits':
                // php-src Zend/zend_builtin_functions.stub.php — arity 0 on every version (#27900).
                return [];
            case 'get_defined_functions':
                return \PHPCompiler\CompilerVersion::supportsGetDefinedFunctionsExcludeDisabled()
                    ? ['exclude_disabled=']
                    : [];
            case 'fdiv':
                // php-src basic_functions.stub.php — exactly num1, num2 (#23576; no rounding_mode).
                return ['num1', 'num2'];
            case 'bcadd':
            case 'bcsub':
            case 'bcmul':
            case 'bcdiv':
            case 'bcmod':
                // php-src ext/bcmath/bcmath.stub.php — scale only; no rounding_mode (#26143, reverts #9946/#9919).
                return ['num1', 'num2', 'scale'];
            // php-src ext/bcmath/bcmath.stub.php — PHP 8.4; not in php-types InternalArgInfo (#24578)
            case 'bcdivmod':
                return ['num1', 'num2', 'scale='];
            // php-src ext/bcmath/bcmath.stub.php — PHP 8.4; absent from php-types InternalArgInfo (#26096)
            case 'bcceil':
            case 'bcfloor':
                return ['num'];
            case 'bcround':
                // php-src — num + optional precision/mode (RoundingMode::HalfAwayFromZero) (#26096, #28566).
                return ['num', 'precision=', 'mode='];
            case 'bcpow':
                // php-src — num/exponent/scale; InternalArgInfo still says x/y (#26145).
                return ['num', 'exponent', 'scale'];
            case 'bcsqrt':
                // php-src — num/scale; InternalArgInfo still says operand (#26145).
                return ['num', 'scale'];
            case 'bcpowmod':
                // php-src — num/exponent/modulus/scale; RoundingMode is bcround-only (#26143).
                return ['num', 'exponent', 'modulus', 'scale'];
            case 'fpow':
                return ['num', 'exponent'];
            // php-src ext/gmp/gmp.stub.php — InternalArgInfo still a/b/gmpnumber/exp/round (#28746)
            case 'gmp_init':
                return ['num', 'base='];
            case 'gmp_import':
                return ['data', 'word_size=', 'flags='];
            case 'gmp_export':
                return ['num', 'word_size=', 'flags='];
            case 'gmp_intval':
            case 'gmp_neg':
            case 'gmp_abs':
            case 'gmp_fact':
            case 'gmp_sqrt':
            case 'gmp_sqrtrem':
            case 'gmp_perfect_square':
            case 'gmp_perfect_power':
            case 'gmp_sign':
            case 'gmp_com':
            case 'gmp_popcount':
            case 'gmp_nextprime':
                return ['num'];
            case 'gmp_strval':
                return ['num', 'base='];
            case 'gmp_add':
            case 'gmp_sub':
            case 'gmp_mul':
            case 'gmp_mod':
            case 'gmp_divexact':
            case 'gmp_gcd':
            case 'gmp_lcm':
            case 'gmp_invert':
            case 'gmp_jacobi':
            case 'gmp_legendre':
            case 'gmp_kronecker':
            case 'gmp_cmp':
            case 'gmp_and':
            case 'gmp_or':
            case 'gmp_xor':
            case 'gmp_hamdist':
            case 'gmp_gcdext':
                return ['num1', 'num2'];
            case 'gmp_div_q':
            case 'gmp_div_r':
            case 'gmp_div_qr':
            case 'gmp_div':
                return ['num1', 'num2', 'rounding_mode='];
            case 'gmp_pow':
                return ['num', 'exponent'];
            case 'gmp_powm':
                return ['num', 'exponent', 'modulus'];
            case 'gmp_prob_prime':
                return ['num', 'repetitions='];
            case 'gmp_random_seed':
                return ['seed'];
            case 'gmp_random_bits':
                return ['bits'];
            case 'gmp_random_range':
                return ['min', 'max'];
            case 'gmp_root':
            case 'gmp_rootrem':
                return ['num', 'nth'];
            case 'gmp_setbit':
                return ['num', 'index', 'value='];
            case 'gmp_clrbit':
                return ['num', 'index'];
            case 'gmp_testbit':
                return ['num', 'index'];
            case 'gmp_scan0':
            case 'gmp_scan1':
                return ['num1', 'start'];
            case 'gmp_binomial':
                return ['n', 'k'];
            case 'intdiv':
                return ['num1', 'num2'];
            case 'atan2':
                return ['y', 'x'];
            case 'hypot':
                return ['x', 'y'];
            case 'random_int':
                return ['min', 'max'];
            // php-src ext/standard/php_mt_rand.c / random.stub.php — both params optional (0 or 2 args) (#24641)
            case 'mt_rand':
                return ['min=', 'max='];
            case 'hex2bin':
                // php-src arity 1 — no $strict (#27763; ext/standard/string.c / string.stub.php)
                return ['string'];
            case 'bindec':
                return ['binary_string'];
            case 'hexdec':
                return ['hex_string'];
            case 'octdec':
                return ['octal_string'];
            case 'decbin':
            case 'dechex':
            case 'decoct':
                return ['num'];
            // php-src ext/standard/basic_functions.stub.php — offset=0 (#24896)
            // InternalArgInfo only lists format/data (no offset row).
            case 'unpack':
                return ['format', 'string', 'offset='];
            case 'openssl_cipher_iv_length':
            case 'openssl_cipher_key_length':
                return ['cipher_algo'];
            // php-src ext/imap/php_imap.stub.php — $string; InternalArgInfo still says buf/in (#27681, #27764)
            case 'imap_utf7_encode':
            case 'imap_utf7_decode':
            case 'imap_utf8_to_mutf7':
            case 'imap_mutf7_to_utf8':
                return ['string'];
            // php-src ext/imap/php_imap.stub.php — imap/message_nums/mailbox/flags; InternalArgInfo drift (#27780)
            case 'imap_mail_copy':
            case 'imap_mail_move':
                return ['imap', 'message_nums', 'mailbox', 'flags='];
            // php-src aliases (#27820) — same arginfo as canonical siblings
            case 'imap_fetchtext':
                return ['imap', 'message_num', 'flags='];
            case 'imap_header':
                return ['imap', 'message_num', 'from_length=', 'subject_length=', 'default_host='];
            case 'imap_create':
                return ['imap', 'mailbox'];
            case 'imap_rename':
                return ['imap', 'from', 'to'];
            case 'imap_listmailbox':
            case 'imap_listsubscribed':
                return ['imap', 'reference', 'pattern'];
            // php-src ext/imap/php_imap.stub.php — PHP 8.3+; absent from InternalArgInfo (#27674)
            case 'imap_is_open':
                return ['imap'];
            // php-src ext/imap/php_imap.stub.php — host→hostname, address_string→string (#27682)
            case 'imap_rfc822_write_address':
                return ['mailbox', 'hostname', 'personal'];
            case 'imap_rfc822_parse_adrlist':
                return ['string', 'default_hostname'];
            case 'imap_rfc822_parse_headers':
                return ['headers', 'default_hostname='];
            // php-src ext/imap/php_imap.stub.php — $string / $mime_encoded_text (#27683)
            case 'imap_base64':
            case 'imap_qprint':
            case 'imap_8bit':
            case 'imap_binary':
            case 'imap_mime_header_decode':
                return ['string'];
            case 'imap_utf8':
                return ['mime_encoded_text'];
            // php-src ext/opcache/opcache.stub.php — InternalArgInfo still says script (#23834)
            case 'opcache_compile_file':
            case 'opcache_is_script_cached':
                return ['filename'];
            case 'opcache_invalidate':
                return ['filename', 'force='];
            case 'opcache_get_status':
                return ['include_scripts='];
            case 'opcache_get_configuration':
            case 'opcache_reset':
                return [];
            // php-src ext/openssl/openssl.stub.php — InternalArgInfo still says returned_strong_result (#23626)
            case 'openssl_random_pseudo_bytes':
                return ['length', 'strong_result'];
            // php-src ext/openssl/openssl.stub.php — InternalArgInfo still says method/raw_output/key (#24365)
            case 'openssl_digest':
                return ['data', 'digest_algo', 'binary'];
            case 'openssl_sign':
                return ['data', 'signature', 'private_key', 'algorithm'];
            case 'openssl_verify':
                return ['data', 'signature', 'public_key', 'algorithm'];
            // php-src ext/openssl/openssl.stub.php — InternalArgInfo still says configargs (#24491)
            case 'openssl_pkey_new':
                return ['options'];
            // php-src ext/openssl/openssl.stub.php — absent from InternalArgInfo (#27685)
            case 'openssl_pkey_derive':
                return ['public_key', 'private_key', 'key_length='];
            // php-src ext/openssl/openssl.stub.php — InternalArgInfo still says out/config_args / outfilename (#24492)
            case 'openssl_pkey_export':
                return ['key', 'output', 'passphrase', 'options'];
            case 'openssl_pkey_export_to_file':
                return ['key', 'output_filename', 'passphrase', 'options'];
            case 'openssl_encrypt':
                // php-src ext/openssl/openssl.stub.php (#21135)
                return ['data', 'cipher_algo', 'passphrase', 'options', 'iv', 'tag', 'aad', 'tag_length'];
            case 'openssl_decrypt':
                return ['data', 'cipher_algo', 'passphrase', 'options', 'iv', 'tag', 'aad'];
            // php-src ext/openssl/openssl.stub.php — InternalArgInfo still says sealdata/ekeys/pubkeys/method (#28754)
            case 'openssl_seal':
                return ['data', 'sealed_data', 'encrypted_keys', 'public_key', 'cipher_algo', 'iv='];
            // php-src ext/openssl/openssl.stub.php — InternalArgInfo still says opendata/ekey/privkey/method (#28754)
            case 'openssl_open':
                return ['data', 'output', 'encrypted_key', 'private_key', 'cipher_algo', 'iv='];
            case 'openssl_cms_verify':
                // php-src ext/openssl/openssl.stub.php (#22368, re-#6592)
                return [
                    'input_filename',
                    'flags',
                    'certificates',
                    'ca_info',
                    'untrusted_certificates_filename',
                    'content',
                    'pk7',
                    'sigfile',
                    'encoding',
                ];
            case 'openssl_cms_sign':
                return [
                    'input_filename',
                    'output_filename',
                    'certificate',
                    'private_key',
                    'headers',
                    'flags',
                    'encoding',
                    'untrusted_certificates_filename',
                ];
            // php-src ext/openssl/openssl.stub.php — InternalArgInfo still says x509/shortnames (#24663)
            case 'openssl_x509_parse':
                return ['certificate', 'short_names='];
            // php-src ext/openssl/openssl.stub.php — InternalArgInfo still says dn/privkey/configargs (#24663)
            case 'openssl_csr_new':
                return ['distinguished_names', 'private_key', 'options=', 'extra_attributes='];
            // php-src ext/openssl/openssl.stub.php — InternalArgInfo still says infile/outfile/signcert (#24663)
            case 'openssl_pkcs7_sign':
                return [
                    'input_filename',
                    'output_filename',
                    'certificate',
                    'private_key',
                    'headers',
                    'flags=',
                    'untrusted_certificates_filename=',
                ];
            // php-src ext/curl/curl.stub.php — InternalArgInfo still says ch/mh/sh (#23594)
            case 'curl_init':
                return ['url='];
            case 'curl_close':
            case 'curl_copy_handle':
            case 'curl_errno':
            case 'curl_error':
            case 'curl_exec':
            case 'curl_reset':
                return ['handle'];
            case 'curl_setopt':
                return ['handle', 'option', 'value'];
            case 'curl_setopt_array':
                return ['handle', 'options'];
            case 'curl_escape':
            case 'curl_unescape':
                return ['handle', 'string'];
            case 'curl_getinfo':
                return ['handle', 'option'];
            case 'curl_pause':
                return ['handle', 'flags'];
            case 'curl_multi_add_handle':
            case 'curl_multi_remove_handle':
                return ['multi_handle', 'handle'];
            case 'curl_multi_close':
                return ['multi_handle'];
            case 'curl_multi_exec':
                return ['multi_handle', 'still_running'];
            case 'curl_multi_getcontent':
                return ['handle'];
            case 'curl_multi_info_read':
                return ['multi_handle', 'queued_messages'];
            case 'curl_multi_select':
                return ['multi_handle', 'timeout'];
            case 'curl_multi_setopt':
                return ['multi_handle', 'option', 'value'];
            case 'curl_share_close':
                return ['share_handle'];
            case 'curl_share_setopt':
                return ['share_handle', 'option', 'value'];
            case 'curl_strerror':
                return ['error_code'];
            // php-src ext/hash/hash.stub.php — options missing from InternalArgInfo (#25068)
            case 'hash':
                return ['algo', 'data', 'binary=', 'options='];
            case 'hash_hmac':
                return ['algo', 'data', 'key', 'binary'];
            // php-src ext/hash/hash.stub.php — InternalArgInfo still says raw_output (#24377)
            case 'hash_hmac_file':
                return ['algo', 'filename', 'key', 'binary'];
            // php-src ext/hash/hash.stub.php — InternalArgInfo still says raw_output (#23586)
            case 'hash_final':
                return ['context', 'binary'];
            // php-src ext/hash/hash.stub.php — InternalArgInfo omits stream_context (#24563)
            case 'hash_update_file':
                return ['context', 'filename', 'stream_context='];
            // php-src ext/hash/hash.stub.php — InternalArgInfo still says handle (#23786)
            case 'hash_update':
                return ['context', 'data'];
            case 'hash_update_stream':
                return ['context', 'stream', 'length='];
            // php-src ext/hash/hash.stub.php — Reflection OK, named binder missing (#24566)
            case 'hash_copy':
                return ['context'];
            // php-src ext/standard/html.stub.php / basic_functions.stub.php (#23786)
            case 'get_html_translation_table':
                return ['table=', 'flags=', 'encoding='];
            case 'ob_get_status':
                return ['full_status='];
            // php-src ext/standard/basic_functions.stub.php — InternalArgInfo still says flag (#24455)
            case 'ob_implicit_flush':
                return ['enable='];
            // php-src ext/standard/image.stub.php — InternalArgInfo still says imagefile/info (#23343)
            case 'getimagesize':
                return ['filename', 'image_info='];
            // php-src ext/standard/image.stub.php — InternalArgInfo still says data/info (#23681)
            case 'getimagesizefromstring':
                return ['string', 'image_info='];
            // php-src ext/standard/image.stub.php — InternalArgInfo still says imagetype (#24459)
            case 'image_type_to_extension':
                return ['image_type', 'include_dot='];
            case 'image_type_to_mime_type':
                return ['image_type'];
            // php-src ext/hash/hash.stub.php — InternalArgInfo had options/key swapped (#23585)
            case 'hash_init':
                return ['algo', 'flags', 'key', 'options'];
            // php-src ext/hash/hash.stub.php — optionals + types (#23595, #25469)
            case 'hash_pbkdf2':
                return ['algo', 'password', 'salt', 'iterations', 'length=', 'binary=', 'options='];
            // php-src ext/standard/basic_functions.stub.php — InternalArgInfo still says str/raw_output (#23227)
            case 'md5':
            case 'sha1':
                return ['string', 'binary'];
            // php-src ext/standard/basic_functions.stub.php — InternalArgInfo still says raw_output (#24549)
            case 'md5_file':
            case 'sha1_file':
                return ['filename', 'binary'];
            // php-src ext/hash/hash.stub.php — missing from InternalArgInfo; optionals + types (#23290, #25018)
            case 'hash_hkdf':
                return ['algo', 'key', 'length=', 'info=', 'salt='];
            // php-src ext/hash/hash.stub.php — Reflection was empty without this map (#23205)
            case 'hash_equals':
                return ['known_string', 'user_string'];
            // php-src ext/iconv/iconv.stub.php — InternalArgInfo still says in_charset/str/charset (#23307)
            case 'iconv':
                return ['from_encoding', 'to_encoding', 'string'];
            case 'iconv_strlen':
                return ['string', 'encoding'];
            case 'iconv_substr':
                return ['string', 'offset', 'length', 'encoding'];
            // php-src ext/iconv/iconv.stub.php — InternalArgInfo still says charset (#24364)
            case 'iconv_strpos':
                return ['haystack', 'needle', 'offset', 'encoding'];
            case 'iconv_strrpos':
                return ['haystack', 'needle', 'encoding'];
            // php-src ext/iconv/iconv.stub.php — InternalArgInfo still says preference (#24567)
            case 'iconv_mime_encode':
                return ['field_name', 'field_value', 'options='];
            // php-src ext/iconv/iconv.stub.php — InternalArgInfo still says encoded_string/charset (#24378)
            case 'iconv_mime_decode':
                return ['string', 'mode=', 'encoding='];
            // php-src ext/iconv/iconv.stub.php — InternalArgInfo still says charset (#24378)
            case 'iconv_mime_decode_headers':
                return ['headers', 'mode=', 'encoding='];
            case 'base64_decode':
                return ['string', 'strict'];
            // php-src ext/standard/basic_functions.stub.php — InternalArgInfo still says str (#23257)
            case 'base64_encode':
            case 'urlencode':
            case 'urldecode':
            case 'rawurlencode':
            case 'rawurldecode':
            // php-src ext/standard/basic_functions.stub.php — InternalArgInfo still says data (#23784)
            case 'convert_uuencode':
            case 'convert_uudecode':
                return ['string'];
            // php-src ext/standard/basic_functions.stub.php — InternalArgInfo still says data (#26235)
            case 'utf8_encode':
            case 'utf8_decode':
                return ['string'];
            // php-src Zend/zend_builtin_functions.stub.php — string|int $status = 0 only (#23957; reverts #6718 two-arg)
            case 'exit':
            case 'die':
                return ['status='];
            // php-src ext/standard/basic_functions.stub.php — numeric_prefix='', arg_separator=null, encoding_type=PHP_QUERY_RFC1738 (#24971)
            case 'http_build_query':
                return ['data', 'numeric_prefix=', 'arg_separator=', 'encoding_type='];
            case 'json_encode':
                return ['value', 'flags', 'depth'];
            // php-src ext/json/json.stub.php — InternalArgInfo omits flags= (#24812)
            case 'json_decode':
                return ['json', 'associative=', 'depth=', 'flags='];
            // php-src ext/json/json.stub.php — missing from InternalArgInfo (#23876)
            case 'json_validate':
                return ['json', 'depth=', 'flags='];
            // php-src ext/ldap/ldap.stub.php — InternalArgInfo still link/host/base_dn/attrs (#24665)
            case 'ldap_connect':
                return ['uri=', 'port='];
            case 'ldap_connect_wallet':
                return ['uri=', 'wallet', 'password', 'auth_mode='];
            case 'ldap_bind':
                return ['ldap', 'dn=', 'password='];
            case 'ldap_bind_ext':
                return ['ldap', 'dn=', 'password=', 'controls='];
            case 'ldap_search':
            case 'ldap_list':
            case 'ldap_read':
                return [
                    'ldap',
                    'base',
                    'filter',
                    'attributes=',
                    'attributes_only=',
                    'sizelimit=',
                    'timelimit=',
                    'deref=',
                    'controls=',
                ];
            // php-src ext/filter/filter.stub.php — filter=FILTER_DEFAULT, options=0 (#25046)
            case 'filter_var':
                return ['value', 'filter=', 'options='];
            // php-src ext/filter/filter.stub.php — InternalArgInfo still says filtername (#23658)
            case 'filter_id':
                return ['name'];
            // php-src ext/filter/filter.stub.php — options=FILTER_DEFAULT, add_empty=true (#23598, #26184)
            case 'filter_var_array':
                return ['array', 'options=', 'add_empty='];
            // php-src ext/filter/filter.stub.php — filter=FILTER_DEFAULT, options=0 (#23383, #26184)
            case 'filter_input':
                return ['type', 'var_name', 'filter=', 'options='];
            // php-src ext/filter/filter.stub.php — InternalArgInfo still says type/variable_name (#26234)
            case 'filter_has_var':
                return ['input_type', 'var_name'];
            // php-src ext/filter/filter.stub.php — options=FILTER_DEFAULT, add_empty=true (#26201)
            case 'filter_input_array':
                return ['type', 'options=', 'add_empty='];
            case 'explode':
                return ['separator', 'string', 'limit'];
            // php-src ext/standard/string.stub.php — array|string $separator, ?array $array = null (#24811)
            // Legacy InternalArgInfo glue/pieces are not runtime named params (#25589).
            case 'implode':
            case 'join':
                return ['separator', 'array='];
            case 'nl2br':
                return ['string', 'use_xhtml'];
            // php-src ext/standard/string.stub.php — InternalArgInfo still says str (#23693)
            case 'hebrev':
            case 'hebrevc': // pre-8.0 profiles only (#20354); same Zend stub names when advertised
                return ['string', 'max_chars_per_line='];
            case 'str_contains':
            case 'str_starts_with':
            case 'str_ends_with':
                return ['haystack', 'needle'];
            // php-src ext/standard/string.stub.php — str_increment/str_decrement (#24577)
            case 'str_increment':
            case 'str_decrement':
                return ['string'];
            // php-src ext/standard/string.stub.php — named dispatch uses forFunction (#23182, re-#16616, #24038)
            case 'strpos':
            case 'stripos':
            case 'strrpos':
            case 'strripos':
                return ['haystack', 'needle', 'offset'];
            case 'strstr':
            case 'strchr':
            case 'stristr':
                // InternalArgInfo: strstr/stristr use `part`, strchr omits 3rd; Zend stub is before_needle (#23218)
                return ['haystack', 'needle', 'before_needle'];
            case 'strrchr':
                // php-src stub is haystack/needle only on 8.2; Reflection already correct via ArgInfo (#24038)
                return ['haystack', 'needle'];
            case 'preg_match':
                return ['pattern', 'subject', 'matches', 'flags', 'offset'];
            case 'preg_match_all':
                return ['pattern', 'subject', 'matches', 'flags', 'offset'];
            case 'preg_split':
                // php-src ext/pcre/php_pcre.stub.php — limit=-1, flags=0 (#24969)
                return ['pattern', 'subject', 'limit=', 'flags='];
            case 'preg_replace':
            case 'preg_filter':
                return ['pattern', 'replacement', 'subject', 'limit', 'count'];
            case 'preg_replace_callback':
                // php-src ext/pcre/php_pcre.c — pattern/callback/subject/limit/count/flags (#19637, #19697, #23587, #24969)
                return ['pattern', 'callback', 'subject', 'limit=', 'count=', 'flags='];
            case 'preg_replace_callback_array':
                // php-src ext/pcre/php_pcre.c — pattern/subject/limit/count/flags (#19697, #24969)
                return ['pattern', 'subject', 'limit=', 'count=', 'flags='];
            case 'preg_grep':
                return ['pattern', 'array', 'flags'];
            // php-src ext/standard/string.stub.php — ?string $delimiter = null (#25472)
            case 'preg_quote':
                return ['str', 'delimiter='];
            case 'file_get_contents':
                return ['filename', 'use_include_path', 'context', 'offset', 'length'];
            case 'file_put_contents':
                return ['filename', 'data', 'flags', 'context'];
            case 'fopen':
                return ['filename', 'mode', 'use_include_path', 'context'];
            case 'stream_get_contents':
                // php-src ext/standard/file.stub.php — ?int $length = null, int $offset = -1 (#25134)
                return ['stream', 'length=', 'offset='];
            // php-src ext/standard/file.stub.php — length (InternalArgInfo still maxlen) (#23921)
            case 'stream_get_line':
                return ['stream', 'length', 'ending='];
            case 'fgets':
            case 'fgetss':
                return ['stream', 'length'];
            case 'fgetcsv':
                return ['stream', 'length', 'separator', 'enclosure', 'escape'];
            // php-src ext/standard/basic_functions.stub.php — separator=","; enclosure="\""; escape="\\" (#24813)
            case 'str_getcsv':
                return ['string', 'separator=', 'enclosure=', 'escape='];
            case 'parse_ini_string':
                return ['ini_string', 'process_sections', 'scanner_mode'];
            case 'parse_ini_file':
                return ['filename', 'process_sections', 'scanner_mode'];
            case 'parse_url':
                return ['url', 'component'];
            // php-src ext/standard/proc_open.stub.php — InternalArgInfo still says env (#23404)
            case 'proc_open':
                return ['command', 'descriptor_spec', 'pipes', 'cwd', 'env_vars', 'options'];
            case 'proc_get_status':
            case 'proc_close':
                return ['process'];
            case 'proc_terminate':
                return ['process', 'signal'];
            // php-src ext/pcntl/pcntl.stub.php — InternalArgInfo still says signo/handle (#24551)
            case 'pcntl_signal':
                return ['signal', 'handler', 'restart_syscalls'];
            // php-src ext/pcntl/pcntl.stub.php — InternalArgInfo still pid/options/rusage (#27849)
            case 'pcntl_waitpid':
                return ['process_id', '&status', 'flags=', '&resource_usage='];
            // php-src ext/pcntl/pcntl.stub.php — ?bool $enable = null (absent from InternalArgInfo) (#28843)
            case 'pcntl_async_signals':
                return ['enable='];
            // php-src ext/standard/basic_functions.stub.php — long_options=[]; &$rest_index=null (#25144)
            case 'getopt':
                return ['short_options', 'long_options=', '&rest_index='];
            case 'call_user_func':
                // php-src ext/standard/basic_functions.stub.php — callable $callback, mixed ...$args (#24461)
                return ['callback', 'args'];
            case 'call_user_func_array':
                return ['callback', 'args'];
            // php-src ext/standard/basic_functions.stub.php — InternalArgInfo still function/parameters (#26237)
            case 'forward_static_call_array':
                return ['callback', 'args'];
            case 'is_callable':
                return ['value', 'syntax_only', 'callable_name'];
            // php-src Zend/zend_builtin_functions.stub.php — arity 0–1 on every profile (#23948, #26369, #28310);
            // $allow_string belongs to is_a / is_subclass_of only (not get_class / get_parent_class).
            case 'get_class':
                return ['object='];
            case 'get_parent_class':
                return ['object_or_class='];
            // php-src Zend/zend_builtin_functions.stub.php — InternalArgInfo still says obj/class (#23401)
            case 'get_object_vars':
                return ['object'];
            // php-src Zend/zend_builtin_functions.stub.php — InternalArgInfo omits this row (#25016)
            case 'get_mangled_object_vars':
                return ['object'];
            case 'get_class_methods':
                return ['object_or_class'];
            // php-src Zend/zend_builtin_functions.stub.php — InternalArgInfo still says class_name (#23947)
            case 'get_class_vars':
                return ['class'];
            // php-src Zend/zend_builtin_functions.stub.php — InternalArgInfo still says object/property_name (#23399)
            case 'method_exists':
                return ['object_or_class', 'method'];
            case 'property_exists':
                return ['object_or_class', 'property'];
            // php-src Zend/zend_builtin_functions.stub.php — InternalArgInfo still says function_name (#23435)
            case 'function_exists':
                return ['function'];
            // php-src Zend/zend_builtin_functions.stub.php — class/alias required; autoload=true (#23422, #25388)
            // InternalArgInfo still marks all three optional (`=`) with bool infer → false.
            case 'class_alias':
                return ['class', 'alias', 'autoload='];
            case 'class_exists':
                return ['class', 'autoload'];
            case 'interface_exists':
                return ['interface', 'autoload'];
            case 'trait_exists':
                return ['trait', 'autoload'];
            case 'enum_exists':
                return ['enum', 'autoload'];
            case 'class_parents':
            case 'class_implements':
            case 'class_uses':
            case 'class_uses_recursive':
                return ['object_or_class', 'autoload'];
            case 'is_subclass_of':
            case 'is_a':
                return ['object_or_class', 'class', 'allow_string'];
            // php-src ext/spl/spl.stub.php — preserve_keys = true (#25066)
            case 'iterator_to_array':
                return ['iterator', 'preserve_keys='];
            // php-src ext/standard/basic_functions.stub.php — $iterator not $it (#23423)
            case 'iterator_count':
                return ['iterator'];
            case 'generator_to_array':
                return ['generator', 'preserve_keys'];
            case 'hrtime':
                return ['as_number'];
            case 'memory_get_usage':
            case 'memory_get_peak_usage':
                return ['real_usage'];
            case 'microtime':
            case 'gettimeofday':
                return ['as_float'];
            case 'sleep':
                return ['seconds'];
            case 'usleep':
                return ['microseconds'];
            case 'http_response_code':
                return ['response_code'];
            // php-src ext/standard/basic_functions.stub.php — InternalArgInfo still says expires (#23360)
            case 'setcookie':
            case 'setrawcookie':
                return ['name', 'value', 'expires_or_options', 'path', 'domain', 'secure', 'httponly'];
            case 'trim':
            case 'ltrim':
            case 'rtrim':
            case 'chop':
                // php-src basic_functions.stub.php — chop is rtrim alias; no $mode (#23224, #24039)
                return ['string', 'characters'];
            case 'mb_strlen':
                return ['string', 'encoding'];
            // php-src ext/mbstring/mbstring.stub.php — ?int $length = null, ?string $encoding = null (#25362)
            case 'mb_substr':
                return ['string', 'start', 'length=', 'encoding='];
            case 'mb_strcut':
                return ['string', 'start', 'length', 'encoding'];
            case 'mb_stripos':
            case 'mb_strpos':
            case 'mb_strripos':
            case 'mb_strrpos':
                return ['haystack', 'needle', 'offset', 'encoding'];
            // php-src ext/mbstring/mbstring.stub.php — bool $before_needle (#23350)
            case 'mb_strstr':
            case 'mb_stristr':
            case 'mb_strrchr':
            case 'mb_strrichr':
                return ['haystack', 'needle', 'before_needle', 'encoding'];
            // php-src ext/mbstring/mbstring.stub.php — string $trim_marker (#23351)
            case 'mb_strimwidth':
                return ['string', 'start', 'width', 'trim_marker', 'encoding'];
            case 'mb_convert_encoding':
                return ['string', 'to_encoding', 'from_encoding'];
            // php-src ext/mbstring/mbstring.stub.php — InternalArgInfo still str/encoding_list (#23623)
            case 'mb_detect_encoding':
                return ['string', 'encodings=', 'strict='];
            // php-src ext/mbstring/mbstring.stub.php — Reflection had empty params (#23291)
            case 'mb_chr':
                return ['codepoint', 'encoding'];
            case 'mb_ord':
                return ['string', 'encoding'];
            case 'mb_scrub':
                return ['string', 'encoding'];
            case 'mb_str_split':
                return ['string', 'length', 'encoding'];
            // php-src ext/mbstring/mbstring.stub.php — optionals + types (#26283)
            case 'mb_trim':
            case 'mb_ltrim':
            case 'mb_rtrim':
                return ['string', 'characters=', 'encoding='];
            // php-src ext/mbstring/mbstring.stub.php — Reflection was empty (#23805)
            case 'mb_str_pad':
                return ['string', 'length', 'pad_string=', 'pad_type=', 'encoding='];
            // php-src ext/mbstring/mbstring.stub.php — types + encoding=null (#26282, re-#23805)
            case 'mb_lcfirst':
            case 'mb_ucfirst':
                return ['string', 'encoding='];
            // php-src ext/mbstring/mbstring.stub.php — InternalArgInfo still str (#23657)
            case 'mb_strtolower':
            case 'mb_strtoupper':
                return ['string', 'encoding='];
            // php-src ext/standard/basic_functions.stub.php — flags/encoding/double_encode optional (#24970)
            case 'htmlspecialchars':
            case 'htmlentities':
                return ['string', 'flags=', 'encoding=', 'double_encode='];
            // php-src ext/standard/basic_functions.stub.php — InternalArgInfo still quote_style (#23265)
            case 'htmlspecialchars_decode':
                return ['string', 'flags='];
            case 'html_entity_decode':
                return ['string', 'flags=', 'encoding='];
            // php-src ext/standard/basic_functions.stub.php — ?string $operator = null (#24971)
            case 'version_compare':
                return ['version1', 'version2', 'operator='];
            case 'in_array':
                return ['needle', 'haystack', 'strict'];
            // php-src ext/zlib/zlib.stub.php — InternalArgInfo still says zp/string/max_decoded_len (#23655)
            case 'gzread':
                return ['stream', 'length'];
            case 'gzwrite':
            case 'gzputs': // alias; Reflection was empty (#24392)
                return ['stream', 'data', 'length='];
            case 'gzclose':
            case 'gzgetc':
            case 'gzeof':
                return ['stream'];
            case 'gzgets': // InternalArgInfo length required; Zend stub length optional (#24392)
                return ['stream', 'length='];
            case 'gzuncompress':
            case 'gzdecode':
            case 'gzinflate':
            case 'zlib_decode': // InternalArgInfo max_decoded_len required; stub max_length=0 (#25132)
                return ['data', 'max_length='];
            // php-src ext/zlib/zlib.stub.php — InternalArgInfo marks level required; stub level=-1 (#25588)
            case 'zlib_encode':
                return ['data', 'encoding', 'level='];
            // php-src ext/zlib/zlib.stub.php — encoding/level optional (#23447)
            case 'gzencode':
            case 'gzcompress':
            case 'gzdeflate':
                return ['data', 'level=', 'encoding='];
            // php-src ext/zlib/zlib.stub.php — options/flush_mode optional; inflate_add data not encoded_data (#23642, #24568)
            case 'inflate_init':
            case 'deflate_init':
                return ['encoding', 'options='];
            case 'deflate_add':
            case 'inflate_add':
                return ['context', 'data', 'flush_mode='];
            case 'array_search':
                return ['needle', 'haystack', 'strict'];
            case 'array_rand':
                return ['array', 'num'];
            case 'array_column':
                return ['array', 'column_key', 'index_key'];
            case 'debug_backtrace':
            case 'get_debug_backtrace':
                return ['options', 'limit'];
            case 'pathinfo':
                return ['path', 'flags'];
            // php-src ext/standard/basic_functions.stub.php — int $levels = 1 (#24971)
            case 'dirname':
                return ['path', 'levels='];
            // php-src ext/standard/file.stub.php / basic_functions.stub.php — string $suffix = "" (#23193 / #24971)
            case 'basename':
                return ['path', 'suffix='];
            case 'uniqid':
                return ['prefix', 'more_entropy'];
            // pecl-networking-uuid uuid.stub.php — uuid_generate_md5/sha1 (#27836)
            case 'uuid_generate_md5':
            case 'uuid_generate_sha1':
                return ['uuid_ns', 'name'];
            case 'gettype':
                // InternalArgInfo still says `var`; Zend stub is value
                return ['value'];
            // php-src ext/standard/basic_functions.stub.php — InternalArgInfo still says var (#23334)
            case 'intval':
                return ['value', 'base='];
            case 'floatval':
            case 'doubleval':
            case 'strval':
            case 'boolval':
                return ['value'];
            // php-src Zend/zend_builtin_functions.stub.php + basic_functions.stub.php (#23263)
            // InternalArgInfo still says `var` (or empty Reflection) for these; Zend stubs use value/num
            case 'get_debug_type':
                return ['value'];
            case 'count':
            case 'sizeof':
                // Zend stubs: Countable|array $value, int $mode = COUNT_NORMAL (0). Encode `=` so
                // sizeof (absent from InternalArgInfo) gets optional mode + required=1 (#25966).
                return ['value', 'mode='];
            case 'is_string':
            case 'is_array':
            case 'is_bool':
            case 'is_int':
            case 'is_integer':
            case 'is_long':
            case 'is_float':
            case 'is_double':
            case 'is_null':
            case 'is_object':
            case 'is_resource':
            case 'is_countable':
            case 'is_iterable':
            case 'is_numeric':
            case 'is_scalar':
                return ['value'];
            case 'is_finite':
            case 'is_infinite':
            case 'is_nan':
                return ['num'];
            case 'array_key_exists':
            case 'key_exists':
                // InternalArgInfo still says `search`; Zend stub is array
                return ['key', 'array'];
            case 'extract':
                return ['array', 'flags', 'prefix'];
            // php-src ext/standard/file.stub.php — InternalArgInfo has context; bare table truncated it (#24454)
            case 'file':
                return ['filename', 'flags', 'context'];
            // php-src ext/fileinfo/fileinfo.stub.php — InternalArgInfo still says options/arg (#23645)
            case 'finfo_open':
                return ['flags=', 'magic_database='];
            // php-src ext/fileinfo/fileinfo.stub.php — InternalArgInfo *file_name/options (#24390)
            case 'finfo_file':
                return ['finfo', 'filename', 'flags=', 'context='];
            case 'finfo_buffer':
                return ['finfo', 'string', 'flags=', 'context='];
            // php-src ext/standard/file.stub.php — InternalArgInfo still says filename_or_stream (#23645)
            case 'mime_content_type':
                return ['filename'];
            case 'glob':
                return ['pattern', 'flags'];
            // php-src ext/standard/string.stub.php — offset=0, ?int $length = null (#23462, #25472)
            case 'substr_count':
                return ['haystack', 'needle', 'offset=', 'length='];
            case 'substr_compare':
                return ['haystack', 'needle', 'offset', 'length', 'case_insensitive'];
            // php-src ext/standard/string.stub.php — InternalArgInfo still says str/repl/start (#23183)
            case 'substr_replace':
                return ['string', 'replace', 'offset', 'length'];
            // php-src ext/standard/string.stub.php — InternalArgInfo still says input/mult (#23204)
            case 'str_repeat':
                return ['string', 'times'];
            // php-src ext/standard/string.stub.php — InternalArgInfo still says str/from/to (#23215)
            case 'strtr':
                return ['string', 'from', 'to'];
            // php-src ext/standard/string.stub.php — InternalArgInfo still says str/allowable_tags (#23217)
            case 'strip_tags':
                return ['string', 'allowed_tags'];
            // php-src ext/standard/string.stub.php — InternalArgInfo still says str/delims (#23226)
            case 'ucwords':
                return ['string', 'separators'];
            // php-src ext/standard/string.stub.php — InternalArgInfo still says str/chunklen/ending (#23206)
            // php-src ext/standard/string.stub.php — length=76, separator="\r\n" (#24971)
            case 'chunk_split':
                return ['string', 'length=', 'separator='];
            // php-src ext/standard/string.stub.php — InternalArgInfo still says str/split_length (#23206)
            // php-src ext/standard/string.stub.php — int $length = 1 (#25044)
            case 'str_split':
                return ['string', 'length='];
            // php-src ext/standard/string.stub.php — string $string, ?string $token = null (#25171)
            // InternalArgInfo still says str / required non-nullable token typed "str".
            case 'strtok':
                return ['string', 'token='];
            // php-src ext/standard/password.stub.php — calleeParamMetadata uses forFunction; verify missing from InternalArgInfo (#23207)
            case 'password_hash':
                return ['password', 'algo', 'options'];
            case 'password_verify':
                return ['password', 'hash'];
            // php-src ext/standard/password.stub.php — absent from InternalArgInfo (#23292)
            case 'password_get_info':
                return ['hash'];
            case 'password_needs_rehash':
                return ['hash', 'algo', 'options='];
            // php-src ext/standard/basic_functions.stub.php — InternalArgInfo still says ascii (#23240)
            case 'chr':
                return ['codepoint'];
            // php-src ext/ctype/ctype.stub.php — InternalArgInfo still says c (#23192)
            case 'ctype_alnum':
            case 'ctype_alpha':
            case 'ctype_cntrl':
            case 'ctype_digit':
            case 'ctype_graph':
            case 'ctype_lower':
            case 'ctype_print':
            case 'ctype_punct':
            case 'ctype_space':
            case 'ctype_upper':
            case 'ctype_xdigit':
                return ['text'];
            case 'file_exists':
            case 'filesize':
            case 'filemtime':
            case 'fileatime':
            case 'filectime':
            case 'fileinode':
            case 'fileowner':
            case 'filegroup':
            case 'fileperms':
            case 'is_file':
            case 'is_dir':
            case 'is_readable':
            case 'is_writable':
            case 'is_writeable':
            case 'is_executable':
            case 'is_link':
            case 'stat':
            case 'lstat':
                return ['filename'];
            // php-src ext/intl/intl_error.stub.php — InternalArgInfo still says error_code (#25587)
            case 'intl_error_name':
                return ['errorCode'];
            // php-src ext/intl/grapheme/grapheme.stub.php — InternalArgInfo still str/start/part/extract_type (#27884)
            case 'grapheme_strlen':
                return ['string'];
            case 'grapheme_substr':
                return ['string', 'offset', 'length='];
            case 'grapheme_strstr':
            case 'grapheme_stristr':
                return ['haystack', 'needle', 'beforeNeedle='];
            case 'grapheme_extract':
                return ['haystack', 'size', 'type=', 'offset=', '&next='];
            case 'grapheme_strpos':
            case 'grapheme_stripos':
            case 'grapheme_strrpos':
            case 'grapheme_strripos':
                return ['haystack', 'needle', 'offset='];
            // php-src ext/intl/php_intl.stub.php — PHP 8.5+ (#27591)
            case 'grapheme_levenshtein':
                return ['string1', 'string2', 'insertion_cost=', 'replacement_cost=', 'deletion_cost=', 'locale='];
            // php-src ext/intl/resourcebundle/resourcebundle.stub.php — bundlename + fallback infer false (#25587)
            case 'resourcebundle_create':
                return ['locale', 'bundle', 'fallback='];
            case 'msgfmt_create':
                return ['locale', 'pattern'];
            case 'msgfmt_format':
                return ['formatter', 'args'];
            case 'msgfmt_format_message':
                return ['locale', 'pattern', 'args'];
            // php-src ext/intl/formatter/formatter.stub.php — InternalArgInfo still has value/fmt/position (#23409)
            case 'numfmt_format_currency':
                return ['formatter', 'amount', 'currency'];
            case 'numfmt_parse_currency':
                return ['formatter', 'string', 'currency', 'offset'];
            case 'transliterator_create':
                return ['id', 'direction'];
            case 'transliterator_transliterate':
                return ['transliterator', 'string', 'start', 'end'];
            // php-src ext/xmlwriter/php_xmlwriter.stub.php — InternalArgInfo still has xmlwriter/content/pubid (#23407, #23608)
            case 'xmlwriter_open_uri':
                return ['uri'];
            case 'xmlwriter_set_indent':
                return ['writer', 'enable'];
            case 'xmlwriter_set_indent_string':
                return ['writer', 'indentation'];
            case 'xmlwriter_flush':
                return ['writer', 'empty='];
            case 'xmlwriter_output_memory':
                return ['writer', 'flush='];
            case 'xmlwriter_start_comment':
            case 'xmlwriter_end_comment':
            case 'xmlwriter_end_attribute':
            case 'xmlwriter_end_element':
            case 'xmlwriter_full_end_element':
            case 'xmlwriter_end_pi':
            case 'xmlwriter_start_cdata':
            case 'xmlwriter_end_cdata':
            case 'xmlwriter_end_document':
            case 'xmlwriter_end_dtd':
            case 'xmlwriter_end_dtd_element':
            case 'xmlwriter_end_dtd_attlist':
            case 'xmlwriter_end_dtd_entity':
                return ['writer'];
            case 'xmlwriter_start_attribute':
            case 'xmlwriter_start_element':
            case 'xmlwriter_start_dtd_attlist':
                return ['writer', 'name'];
            case 'xmlwriter_write_attribute':
                return ['writer', 'name', 'value'];
            case 'xmlwriter_start_attribute_ns':
            case 'xmlwriter_start_element_ns':
                return ['writer', 'prefix', 'name', 'namespace'];
            case 'xmlwriter_write_attribute_ns':
                return ['writer', 'prefix', 'name', 'namespace', 'value'];
            case 'xmlwriter_write_element':
                return ['writer', 'name', 'content='];
            case 'xmlwriter_write_element_ns':
                return ['writer', 'prefix', 'name', 'namespace', 'content='];
            case 'xmlwriter_start_pi':
                return ['writer', 'target'];
            case 'xmlwriter_write_pi':
                return ['writer', 'target', 'content'];
            case 'xmlwriter_write_cdata':
            case 'xmlwriter_text':
            case 'xmlwriter_write_raw':
            case 'xmlwriter_write_comment':
                return ['writer', 'content'];
            case 'xmlwriter_start_document':
                return ['writer', 'version=', 'encoding=', 'standalone='];
            case 'xmlwriter_start_dtd':
                return ['writer', 'qualifiedName', 'publicId=', 'systemId='];
            case 'xmlwriter_write_dtd':
                return ['writer', 'name', 'publicId=', 'systemId=', 'content='];
            case 'xmlwriter_start_dtd_element':
                return ['writer', 'qualifiedName'];
            case 'xmlwriter_write_dtd_element':
            case 'xmlwriter_write_dtd_attlist':
                return ['writer', 'name', 'content'];
            case 'xmlwriter_start_dtd_entity':
                return ['writer', 'name', 'isParam'];
            case 'xmlwriter_write_dtd_entity':
                return ['writer', 'name', 'content', 'isParam=', 'publicId=', 'systemId=', 'notationData='];
            // php-src ext/xml/xml.stub.php — InternalArgInfo still says code (#30651)
            case 'xml_error_string':
                return ['error_code'];
            // php-src ext/xml/xml.stub.php — InternalArgInfo still has shdl/ehdl (#23624)
            case 'xml_set_element_handler':
                return ['parser', 'start_handler', 'end_handler'];
            // php-src ext/xml/xml.stub.php — InternalArgInfo still has hdl (#26589)
            case 'xml_set_character_data_handler':
            case 'xml_set_default_handler':
            case 'xml_set_end_namespace_decl_handler':
            case 'xml_set_external_entity_ref_handler':
            case 'xml_set_notation_decl_handler':
            case 'xml_set_processing_instruction_handler':
            case 'xml_set_start_namespace_decl_handler':
            case 'xml_set_unparsed_entity_decl_handler':
                return ['parser', 'handler'];
            // php-src ext/xml/xml.stub.php — InternalArgInfo still has &obj (#23946)
            case 'xml_set_object':
                return ['parser', 'object'];
            // php-src ext/xml/xml.stub.php — InternalArgInfo still says isfinal (#23605)
            case 'xml_parse':
                return ['parser', 'data', 'is_final='];
            // php-src ext/xml/xml.stub.php — InternalArgInfo still has sep= (#26687)
            case 'xml_parser_create_ns':
                return ['encoding=', 'separator='];
            // php-src ext/xml/xml.stub.php — values/index by-ref; InternalArgInfo types array (#26687)
            case 'xml_parse_into_struct':
                return ['parser', 'data', '&values', '&index='];
            // php-src ext/libxml/libxml.stub.php — InternalArgInfo still says streams_context (#26236)
            case 'libxml_set_streams_context':
                return ['context'];
            // php-src ext/standard/basic_functions.stub.php — InternalArgInfo still says additional_parameters (#23605)
            case 'mail':
                return ['to', 'subject', 'message', 'additional_headers=', 'additional_params='];
            // php-src ext/standard/basic_functions.stub.php — InternalArgInfo still extra_headers (#23341)
            case 'error_log':
                return ['message', 'message_type=', 'destination=', 'additional_headers='];
            // php-src ext/sodium/sodium.stub.php — missing from InternalArgInfo (#23605)
            case 'sodium_memzero':
                return ['&string'];
            // php-src ext/sodium/libsodium.stub.php — absent from InternalArgInfo (#27734)
            case 'sodium_pad':
            case 'sodium_unpad':
                return ['string', 'block_size'];
            // php-src ext/sodium/sodium_*.stub.php — Reflection empty without this map (#24490)
            case 'sodium_crypto_generichash':
                return ['message', 'key=', 'length='];
            case 'sodium_crypto_secretbox':
                return ['message', 'nonce', 'key'];
            // php-src ext/sodium/libsodium.stub.php — ciphertext (not message); absent from InternalArgInfo (#28856)
            case 'sodium_crypto_secretbox_open':
                return ['ciphertext', 'nonce', 'key'];
            case 'sodium_crypto_box':
                return ['message', 'nonce', 'key_pair'];
            case 'sodium_crypto_sign':
                return ['message', 'secret_key'];
            // php-src ext/sodium/libsodium.stub.php — absent from InternalArgInfo (#28753)
            case 'sodium_crypto_sign_detached':
                return ['message', 'secret_key'];
            case 'sodium_crypto_sign_verify_detached':
                return ['signature', 'message', 'public_key'];
            case 'sodium_crypto_box_seal':
                return ['message', 'public_key'];
            case 'sodium_crypto_pwhash_str':
                return ['password', 'opslimit', 'memlimit'];
            // php-src ext/exif/exif.stub.php — InternalArgInfo still says filename/sections_needed/sub_arrays (#23605)
            case 'exif_read_data':
                return ['file', 'required_sections=', 'as_arrays=', 'read_thumbnail='];
            // php-src ext/exif/exif.stub.php — InternalArgInfo imagefile (#24458)
            case 'exif_imagetype':
                return ['filename'];
            // php-src ext/exif/exif.stub.php — InternalArgInfo filename/imagetype (#24457)
            case 'exif_thumbnail':
                return ['file', '&width=', '&height=', '&image_type='];
            // php-src ext/simplexml/simplexml.stub.php — InternalArgInfo still has ns (#23455)
            case 'simplexml_load_string':
                return ['data', 'class_name', 'options', 'namespace_or_prefix', 'is_prefix'];
            case 'simplexml_load_file':
                return ['filename', 'class_name', 'options', 'namespace_or_prefix', 'is_prefix'];
            // php-src ext/standard/basic_functions.stub.php — InternalArgInfo still says file_name (#23785)
            case 'highlight_file':
            case 'show_source':
                return ['filename', 'return='];
            case 'php_strip_whitespace':
                return ['filename'];
            // php-src stub string/return; ensure named string: binds (#23785)
            case 'highlight_string':
                return ['string', 'return='];
            // php-src ext/standard/info.stub.php — InternalArgInfo still says what (#24550)
            case 'phpinfo':
                return ['flags'];
        }

        return null;
    }

    /**
     * Parameter count for internal builtins (BuiltinParamNames first, then InternalArgInfo; #11453).
     *
     * Zend stub arity wins for known internal variadics (#22825).
     */
    public static function paramCountForInternalFunction(string $name): ?int
    {
        $meta = self::zendInternalVariadicReflectionArity($name);
        if (null !== $meta) {
            return $meta['total'];
        }
        $names = self::paramNamesForInternalFunction($name);
        if (null === $names) {
            return null;
        }
        $count = \count($names);
        $variadic = self::variadicParamIndexForFunction($name);
        if (null !== $variadic) {
            $count = max($count, $variadic + 1);
        }

        return $count;
    }

    /**
     * Parameter names for internal functions (explicit table first, InternalArgInfo fallback; #18337).
     *
     * @return list<string>|null
     */
    public static function paramNamesForInternalFunction(string $name): ?array
    {
        if (str_contains($name, '::')) {
            $explicit = self::forClassMethod(strtolower($name));
            if (null !== $explicit) {
                return $explicit;
            }
            [$class, $method] = explode('::', $name, 2);
            $fromArgInfo = BuiltinInternalArgInfo::paramNamesForClassMethod($class, $method);
            if ([] !== $fromArgInfo) {
                return $fromArgInfo;
            }

            return null;
        }

        $explicit = self::forFunction($name);
        if (null !== $explicit) {
            return $explicit;
        }
        $count = BuiltinInternalArgInfo::paramCountForFunction($name);
        if (null === $count) {
            return null;
        }
        $names = [];
        for ($i = 0; $i < $count; $i++) {
            $info = BuiltinInternalArgInfo::paramInfoForFunction($name, $i);
            if (null === $info) {
                return null;
            }
            $names[] = $info['name'];
        }

        return $names;
    }

    public static function paramCountForInternalMethod(string $class, string $method): ?int
    {
        $names = self::forClassMethod(strtolower($class).'::'.strtolower($method));
        if (null !== $names) {
            return \count($names);
        }

        return BuiltinInternalArgInfo::paramCountForClassMethod($class, $method);
    }

    public static function requiredParamCountForInternalFunction(string $name): ?int
    {
        $meta = self::zendInternalVariadicReflectionArity($name);
        if (null !== $meta) {
            return $meta['required'];
        }
        $names = self::forFunction($name);
        if (null !== $names) {
            // Bare name tables are for named-arg dispatch; optionality lives in
            // InternalArgInfo (`=` markers). Only trust names when they encode optionals (#23181).
            if (self::namesEncodeOptionalParams(array_values($names))) {
                $required = self::requiredParamCountFromNames(array_values($names));
                $variadic = self::variadicParamIndexForFunction($name);
                if (null !== $variadic) {
                    $required = min($required, $variadic);
                }

                return $required;
            }
            $fromArgInfo = BuiltinInternalArgInfo::requiredParamCountForFunction($name);
            if (null !== $fromArgInfo) {
                return $fromArgInfo;
            }

            return self::requiredParamCountFromNames(array_values($names));
        }

        return BuiltinInternalArgInfo::requiredParamCountForFunction($name);
    }

    public static function requiredParamCountForInternalMethod(string $class, string $method): ?int
    {
        $names = self::forClassMethod(strtolower($class).'::'.strtolower($method));
        if (null !== $names) {
            // Bare name tables are for named-arg / Reflection labels; optionality lives in
            // InternalArgInfo (`=` markers). Match forFunction() (#23391, DateTime req count).
            if (self::namesEncodeOptionalParams(array_values($names))) {
                return self::requiredParamCountFromNames($names);
            }
            $fromArgInfo = BuiltinInternalArgInfo::requiredParamCountForClassMethod($class, $method);
            if (null !== $fromArgInfo) {
                return $fromArgInfo;
            }

            return self::requiredParamCountFromNames($names);
        }

        return BuiltinInternalArgInfo::requiredParamCountForClassMethod($class, $method);
    }

    /**
     * @param list<int|string> $names
     */
    private static function requiredParamCountFromNames(array $names): int
    {
        $required = 0;
        foreach ($names as $name) {
            $label = (string) $name;
            if (str_ends_with($label, '=') || str_starts_with($label, '...')) {
                break;
            }
            ++$required;
        }

        return $required;
    }

    /**
     * True when the name table marks optionals with trailing `=` or a `...` variadic (#23181).
     *
     * @param list<int|string> $names
     */
    public static function namesEncodeOptionalParams(array $names): bool
    {
        foreach ($names as $name) {
            $label = (string) $name;
            if (str_ends_with($label, '=') || str_starts_with($label, '...')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Optionality from a stub override entry (`=` / `...`), ignoring InternalArgInfo (#25147).
     */
    public static function overrideEntryIsOptional(string $rawName): bool
    {
        if (str_ends_with($rawName, '=')) {
            return true;
        }
        $n = ltrim($rawName, '&');

        return str_starts_with($n, '...');
    }

    /**
     * Variadic parameter index for builtins that accept ...$args (#10637, #22825).
     *
     * Prefer Zend stub reflection arity; fall back to InternalArgInfo `...` markers.
     */
    public static function variadicParamIndexForFunction(string $name): ?int
    {
        $meta = self::zendInternalVariadicReflectionArity($name);
        if (null !== $meta) {
            return $meta['index'];
        }
        // Explicit stub tables may encode ...$name (class methods + free functions, #24591).
        $names = str_contains($name, '::')
            ? self::forClassMethod(strtolower($name))
            : self::forFunction($name);
        if (null !== $names) {
            foreach ($names as $index => $label) {
                $n = ltrim((string) $label, '&');
                if (str_starts_with($n, '...')) {
                    return $index;
                }
            }
        }

        return BuiltinInternalArgInfo::variadicParamIndexForFunction($name);
    }

    /**
     * php-src stub reflection arity for internal variadics (ext/standard/*.stub.php, #22825).
     *
     * Legacy InternalArgInfo often keeps pre-stub shapes (e.g. sprintf format+arg1+... → tot=3);
     * Zend ReflectionFunction reports the stub shape (format+...values → tot=2).
     *
     * @return array{index: int, required: int, total: int}|null
     */
    private static function zendInternalVariadicReflectionArity(string $name): ?array
    {
        return match (strtolower($name)) {
            'sprintf',
            'printf',
            'pack' => ['index' => 1, 'required' => 1, 'total' => 2],
            'fprintf',
            'sscanf',
            'fscanf',
            'vfscanf' => ['index' => 2, 'required' => 2, 'total' => 3],
            'array_merge',
            'array_merge_recursive' => ['index' => 0, 'required' => 0, 'total' => 1],
            'array_push',
            'array_unshift',
            'array_replace',
            'array_replace_recursive',
            'array_diff',
            'array_diff_assoc',
            'array_diff_key',
            'array_diff_uassoc',
            'array_diff_ukey',
            'array_intersect',
            'array_intersect_assoc',
            'array_intersect_key',
            'array_intersect_uassoc',
            'array_intersect_ukey',
            'array_udiff',
            'array_udiff_assoc',
            'array_udiff_uassoc',
            'array_uintersect',
            'array_uintersect_assoc',
            'array_uintersect_uassoc',
            'array_multisort',
            'call_user_func',
            'forward_static_call',
            'compact',
            'var_dump',
            'debug_zval_dump',
            'register_shutdown_function',
            'register_tick_function',
            'max',
            'min' => ['index' => 1, 'required' => 1, 'total' => 2],
            'array_map' => ['index' => 2, 'required' => 2, 'total' => 3],
            'setlocale' => ['index' => 2, 'required' => 2, 'total' => 3],
            'mb_convert_variables' => ['index' => 3, 'required' => 3, 'total' => 4],
            default => null,
        };
    }

    /**
     * php-src rejects all named parameters on these variadic builtins (#11349, #23804).
     *
     * array_merge/array_replace accept only repeated variadic-slot names (overwrite Error);
     * single named args still end in ArgumentCountError via deferred unknown-named resolution.
     */
    public static function rejectsNamedParameters(string $name): bool
    {
        return 'pack' === strtolower($name);
    }

    /**
     * Internals that use Z_PARAM_VARIADIC_WITH_NAMED and forward unknown names to the callee (#23772).
     *
     * Most internal variadics reject unknown named args (#23449); exceptions forward into the
     * variadic pack (php-src call_user_func; ReflectionFunction/Method::invoke, #24949).
     * forward_static_call does not.
     */
    public static function forwardsNamedArgsIntoVariadic(string $name): bool
    {
        $lc = strtolower($name);

        return 'call_user_func' === $lc
            || 'reflectionfunction::invoke' === $lc
            || 'reflectionmethod::invoke' === $lc;
    }

    /**
     * @throws \ArgumentCountError
     */
    public static function throwUnknownNamedParameterError(string $name): never
    {
        throw new \ArgumentCountError(strtolower($name).'() does not accept unknown named parameters');
    }

    /**
     * Zend internal arity message when named/unpack left required params empty (#23449).
     *
     * @throws \ArgumentCountError
     */
    public static function throwTooFewArgumentsError(string $name, int $required, int $given): never
    {
        $argWord = 1 === $required ? 'argument' : 'arguments';
        throw new \ArgumentCountError(
            sprintf(
                '%s() expects at least %d %s, %d given',
                strtolower($name),
                $required,
                $argWord,
                $given
            )
        );
    }

    /**
     * PHP 8.4+ named-parameter aliases (php-src arginfo alias tables).
     *
     * @return array<string, int> lowercase alias => parameter index
     */
    public static function aliasesForFunction(string $name): array
    {
        $lc = strtolower($name);
        if (str_contains($lc, '::')) {
            return self::aliasesForClassMethod($lc);
        }
        // implode/join: do NOT alias glue/pieces — Zend 8.2+ stubs use separator/array only;
        // named glue/pieces is Unknown named parameter (#25589, reverts #9985 over-accept).
        // array_column: do NOT alias input→array — Zend stubs use $array only;
        // named input is Unknown named parameter (#25592, reverts #10042 over-accept).
        // fgetcsv/str_getcsv: do NOT alias delimiter→separator — Zend 8.2+ stubs already use
        // separator; named delimiter is Unknown named parameter (#25590, reverts #12018 over-accept).

        return [];
    }

    /**
     * Public stub names that differ from internal arginfo (#11785, DateTime::createFromFormat datetime).
     *
     * @return array<string, int>
     */
    public static function aliasesForClassMethod(string $qualifiedMethod): array
    {
        $lc = strtolower($qualifiedMethod);
        if (str_ends_with($lc, '::createfromformat')) {
            return ['datetime' => 1];
        }
        // SplFileObject::fgetcsv/fputcsv: Zend stubs use separator; delimiter is rejected (#25590).

        return [];
    }

    /**
     * @param list<string> $paramNames
     */
    public static function lookupNamedParamIndex(array $paramNames, string $namedParam, ?string $function = null): int|false
    {
        $lc = strtolower($namedParam);
        // InternalArgInfo may prefix by-ref params with '&' (e.g. '&count'); callers use bare names (#19697).
        $lowerNames = array_map(
            static function (string $name): string {
                $n = ltrim($name, '&');
                if (str_starts_with($n, '...')) {
                    $n = substr($n, 3);
                }

                return strtolower(rtrim($n, '='));
            },
            $paramNames
        );
        $idx = array_search($lc, $lowerNames, true);
        if (false !== $idx) {
            return $idx;
        }
        if (null !== $function) {
            $aliases = self::aliasesForFunction($function);
            if (isset($aliases[$lc])) {
                return $aliases[$lc];
            }
        }

        return false;
    }
}
