<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\CompilerVersion;
use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\ResourceSupport;
use PHPCompiler\VM\Variable;

/**
 * XMLWriter streaming serializer — PHP-in-PHP (php-src ext/xmlwriter/php_xmlwriter.c; #6065, #19340).
 */
final class VmXmlWriter
{
    public const CLASS_LC = 'xmlwriter';

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $pub = \PHPCfg\Func::FLAG_PUBLIC;
        $pubStatic = $pub | \PHPCfg\Func::FLAG_STATIC;

        $entry = new ClassEntry('XMLWriter');
        $entry->methods['openmemory'] = new XmlWriterOpenMemory();
        $entry->methodVisibility['openmemory'] = $pub;
        $entry->methodNames['openmemory'] = 'openMemory';
        $entry->methods['openuri'] = new XmlWriterOpenURI();
        $entry->methodVisibility['openuri'] = $pub;
        $entry->methodNames['openuri'] = 'openURI';
        $entry->methods['setindent'] = new XmlWriterSetIndent();
        $entry->methodVisibility['setindent'] = $pub;
        $entry->methodNames['setindent'] = 'setIndent';
        $entry->methods['setindentstring'] = new XmlWriterSetIndentString();
        $entry->methodVisibility['setindentstring'] = $pub;
        $entry->methodNames['setindentstring'] = 'setIndentString';
        $entry->methods['startdocument'] = new XmlWriterStartDocument();
        $entry->methodVisibility['startdocument'] = $pub;
        $entry->methodNames['startdocument'] = 'startDocument';
        $entry->methods['startelement'] = new XmlWriterStartElement();
        $entry->methodVisibility['startelement'] = $pub;
        $entry->methodNames['startelement'] = 'startElement';
        $entry->methods['writeattribute'] = new XmlWriterWriteAttribute();
        $entry->methodVisibility['writeattribute'] = $pub;
        $entry->methodNames['writeattribute'] = 'writeAttribute';
        $entry->methods['writeattributens'] = new XmlWriterWriteAttributeNS();
        $entry->methodVisibility['writeattributens'] = $pub;
        $entry->methodNames['writeattributens'] = 'writeAttributeNS';
        $entry->methods['startelementns'] = new XmlWriterStartElementNS();
        $entry->methodVisibility['startelementns'] = $pub;
        $entry->methodNames['startelementns'] = 'startElementNS';
        $entry->methods['startattributens'] = new XmlWriterStartAttributeNS();
        $entry->methodVisibility['startattributens'] = $pub;
        $entry->methodNames['startattributens'] = 'startAttributeNS';
        $entry->methods['startattribute'] = new XmlWriterStartAttribute();
        $entry->methodVisibility['startattribute'] = $pub;
        $entry->methodNames['startattribute'] = 'startAttribute';
        $entry->methods['endattribute'] = new XmlWriterEndAttribute();
        $entry->methodVisibility['endattribute'] = $pub;
        $entry->methodNames['endattribute'] = 'endAttribute';
        $entry->methods['writeelement'] = new XmlWriterWriteElement();
        $entry->methodVisibility['writeelement'] = $pub;
        $entry->methodNames['writeelement'] = 'writeElement';
        $entry->methods['writeelementns'] = new XmlWriterWriteElementNS();
        $entry->methodVisibility['writeelementns'] = $pub;
        $entry->methodNames['writeelementns'] = 'writeElementNS';
        $entry->methods['writecdata'] = new XmlWriterWriteCData();
        $entry->methodVisibility['writecdata'] = $pub;
        $entry->methodNames['writecdata'] = 'writeCData';
        $entry->methods['startcdata'] = new XmlWriterStartCData();
        $entry->methodVisibility['startcdata'] = $pub;
        $entry->methodNames['startcdata'] = 'startCData';
        $entry->methods['endcdata'] = new XmlWriterEndCData();
        $entry->methodVisibility['endcdata'] = $pub;
        $entry->methodNames['endcdata'] = 'endCData';
        $entry->methods['startpi'] = new XmlWriterStartPI();
        $entry->methodVisibility['startpi'] = $pub;
        $entry->methodNames['startpi'] = 'startPI';
        $entry->methods['endpi'] = new XmlWriterEndPI();
        $entry->methodVisibility['endpi'] = $pub;
        $entry->methodNames['endpi'] = 'endPI';
        $entry->methods['writepi'] = new XmlWriterWritePI();
        $entry->methodVisibility['writepi'] = $pub;
        $entry->methodNames['writepi'] = 'writePI';
        $entry->methods['writeraw'] = new XmlWriterWriteRaw();
        $entry->methodVisibility['writeraw'] = $pub;
        $entry->methodNames['writeraw'] = 'writeRaw';
        $entry->methods['writecomment'] = new XmlWriterWriteComment();
        $entry->methodVisibility['writecomment'] = $pub;
        $entry->methodNames['writecomment'] = 'writeComment';
        $entry->methods['startcomment'] = new XmlWriterStartComment();
        $entry->methodVisibility['startcomment'] = $pub;
        $entry->methodNames['startcomment'] = 'startComment';
        $entry->methods['endcomment'] = new XmlWriterEndComment();
        $entry->methodVisibility['endcomment'] = $pub;
        $entry->methodNames['endcomment'] = 'endComment';
        $entry->methods['startdtd'] = new XmlWriterStartDtd();
        $entry->methodVisibility['startdtd'] = $pub;
        $entry->methodNames['startdtd'] = 'startDtd';
        $entry->methods['enddtd'] = new XmlWriterEndDtd();
        $entry->methodVisibility['enddtd'] = $pub;
        $entry->methodNames['enddtd'] = 'endDtd';
        $entry->methods['writedtd'] = new XmlWriterWriteDtd();
        $entry->methodVisibility['writedtd'] = $pub;
        $entry->methodNames['writedtd'] = 'writeDtd';
        $entry->methods['text'] = new XmlWriterText();
        $entry->methodVisibility['text'] = $pub;
        $entry->methodNames['text'] = 'text';
        $entry->methods['endelement'] = new XmlWriterEndElement();
        $entry->methodVisibility['endelement'] = $pub;
        $entry->methodNames['endelement'] = 'endElement';
        $entry->methods['fullendelement'] = new XmlWriterFullEndElement();
        $entry->methodVisibility['fullendelement'] = $pub;
        $entry->methodNames['fullendelement'] = 'fullEndElement';
        $entry->methods['enddocument'] = new XmlWriterEndDocument();
        $entry->methodVisibility['enddocument'] = $pub;
        $entry->methodNames['enddocument'] = 'endDocument';
        $entry->methods['outputmemory'] = new XmlWriterOutputMemory();
        $entry->methodVisibility['outputmemory'] = $pub;
        $entry->methodNames['outputmemory'] = 'outputMemory';
        $entry->methods['flush'] = new XmlWriterFlush();
        $entry->methodVisibility['flush'] = $pub;
        $entry->methodNames['flush'] = 'flush';

        if (CompilerVersion::supportsXmlWriterFactories()) {
            $entry->methods['tomemory'] = new XmlWriterToMemory();
            $entry->methodVisibility['tomemory'] = $pubStatic;
            $entry->methodNames['tomemory'] = 'toMemory';
            $entry->methods['touri'] = new XmlWriterToUri();
            $entry->methodVisibility['touri'] = $pubStatic;
            $entry->methodNames['touri'] = 'toUri';
            $entry->methods['tostream'] = new XmlWriterToStream();
            $entry->methodVisibility['tostream'] = $pubStatic;
            $entry->methodNames['tostream'] = 'toStream';
        }

        $ctx->classes[self::CLASS_LC] = $entry;
        $ctx->classes[self::CLASS_LC]->isInternal = true;
    }

    public static function requireWriter(ObjectEntry $entry, string $label): ObjectEntry
    {
        if (self::CLASS_LC !== strtolower($entry->class->name)) {
            throw new \TypeError(sprintf('%s(): Argument must be XMLWriter, %s given', $label, $entry->class->name));
        }

        return $entry;
    }

    public static function ensureState(ObjectEntry $entry): XmlWriterState
    {
        if (!XmlWriterRegistry::has($entry)) {
            $state = new XmlWriterState();
            XmlWriterRegistry::attach($entry, $state);
        }

        return XmlWriterRegistry::state($entry);
    }

    public static function openMemory(ObjectEntry $entry): bool
    {
        $state = self::ensureState($entry);
        self::resetState($state, 'memory', null);

        return true;
    }

    public static function newWriter(Context $ctx): ObjectEntry
    {
        $class = $ctx->classes[self::CLASS_LC] ?? null;
        if (null === $class) {
            throw new \LogicException('XMLWriter is not registered in this compiler build');
        }

        return new ObjectEntry($class);
    }

    /**
     * XMLWriter::toMemory() — static in-memory factory (php-src zim_XMLWriter_toMemory; #19606).
     */
    public static function toMemory(Context $ctx): ObjectEntry
    {
        $entry = self::newWriter($ctx);
        self::openMemory($entry);

        return $entry;
    }

    /**
     * XMLWriter::toUri() — static URI factory (php-src zim_XMLWriter_toUri; #19606).
     */
    public static function toUri(Context $ctx, string $uri): ObjectEntry
    {
        if ('' === $uri) {
            throw new \ValueError('XMLWriter::toUri(): Argument #1 ($uri) cannot be empty');
        }
        $entry = self::newWriter($ctx);
        if (!self::openURI($entry, $uri)) {
            throw new \Error('XMLWriter::toUri(): Unable to open URI');
        }

        return $entry;
    }

    /**
     * XMLWriter::toStream() — static stream factory (php-src zim_XMLWriter_toStream; #19606).
     */
    public static function toStream(Context $ctx, Variable $streamVar): ObjectEntry
    {
        $entry = self::newWriter($ctx);
        if (!self::openStream($entry, $streamVar)) {
            throw new \Error('XMLWriter::toStream(): Unable to open stream');
        }

        return $entry;
    }

    public static function openStream(ObjectEntry $entry, Variable $streamVar): bool
    {
        if (!$streamVar->isStreamResource()) {
            throw new \TypeError(
                'XMLWriter::toStream(): Argument #1 ($stream) must be of type resource'
            );
        }
        if (!ResourceSupport::isOpenStreamResource($streamVar)) {
            throw new \ValueError(
                'XMLWriter::toStream(): Argument #1 ($stream) is not an open stream resource'
            );
        }
        $handle = ResourceSupport::resolveHandle($streamVar);
        if (null === $handle) {
            return false;
        }

        $state = self::ensureState($entry);
        self::resetState($state, 'stream', null);
        $state->streamHandle = $handle;

        return true;
    }

    public static function openURI(ObjectEntry $entry, string $uri): bool
    {
        $dir = \dirname($uri);
        if ('' !== $dir && '.' !== $dir && !is_dir($dir)) {
            return false;
        }
        if (is_file($uri) && !is_writable($uri)) {
            return false;
        }
        if (!is_file($uri) && '' !== $dir && '.' !== $dir && !is_writable($dir)) {
            return false;
        }

        $state = self::ensureState($entry);
        self::resetState($state, 'uri', $uri);

        return true;
    }

    public static function setIndent(ObjectEntry $entry, bool $enable): bool
    {
        $state = self::requireOpen($entry, 'XMLWriter::setIndent()');
        $state->indent = $enable;

        return true;
    }

    public static function setIndentString(ObjectEntry $entry, string $indentation): bool
    {
        $state = self::requireOpen($entry, 'XMLWriter::setIndentString()');
        $state->indentString = $indentation;

        return true;
    }

    public static function startDocument(
        ObjectEntry $entry,
        ?string $version = '1.0',
        ?string $encoding = null
    ): bool {
        $state = self::requireOpen($entry, 'XMLWriter::startDocument()');
        if ($state->documentStarted) {
            return false;
        }
        $version = null === $version || '' === $version ? '1.0' : $version;
        $state->version = $version;
        $state->encoding = $encoding;
        $state->documentStarted = true;

        $decl = '<?xml version="'.$version.'"';
        if (null !== $encoding && '' !== $encoding) {
            $decl .= ' encoding="'.self::escapeAttribute($encoding).'"';
        }
        $state->buffer .= $decl."?>\n";

        return true;
    }

    public static function startElement(ObjectEntry $entry, string $name): bool
    {
        $state = self::requireOpen($entry, 'XMLWriter::startElement()');
        if (!self::isValidElementName($name)) {
            throw new \ValueError(sprintf(
                'XMLWriter::startElement(): Argument #1 ($name) must be a valid element name, %s given',
                var_export($name, true)
            ));
        }
        self::closeStartTagIfOpen($state);
        if ([] !== $state->elementStack) {
            if ($state->indent) {
                $parentIdx = \count($state->elementStack) - 1;
                $state->elementStack[$parentIdx]['hasIndentedChild'] = true;
                $state->buffer .= "\n".str_repeat($state->indentString, \count($state->elementStack));
            }
        }
        $state->elementStack[] = ['name' => $name, 'hasIndentedChild' => false];
        $state->buffer .= '<'.self::escapeElementName($name);
        $state->startTagOpen = true;

        return true;
    }

    public static function writeAttribute(ObjectEntry $entry, string $name, string $value): bool
    {
        $state = self::requireOpen($entry, 'XMLWriter::writeAttribute()');
        if (!$state->startTagOpen || [] === $state->elementStack) {
            return false;
        }
        if (!self::isValidAttributeName($name)) {
            throw new \ValueError(sprintf(
                'XMLWriter::writeAttribute(): Argument #1 ($name) must be a valid attribute name, %s given',
                var_export($name, true)
            ));
        }
        self::endOpenAttributeIfNeeded($state);
        $state->buffer .= ' '.self::escapeElementName($name).'="'.self::escapeAttribute($value).'"';

        return true;
    }

    /**
     * XMLWriter::writeAttributeNS — namespaced attribute (+ deferred xmlns).
     * php-src: zim_XMLWriter_writeAttributeNS / xmlTextWriterWriteAttributeNS (#19371).
     */
    public static function writeAttributeNS(
        ObjectEntry $entry,
        ?string $prefix,
        string $name,
        ?string $uri,
        string $content
    ): bool {
        $state = self::requireOpen($entry, 'XMLWriter::writeAttributeNS()');
        if (!$state->startTagOpen || [] === $state->elementStack) {
            return false;
        }
        if (!self::isValidAttributeName($name)) {
            throw new \ValueError(sprintf(
                'XMLWriter::writeAttributeNS(): Argument #2 ($name) must be a valid attribute name, %s given',
                var_export($name, true)
            ));
        }
        if (null !== $prefix && '' !== $prefix && !self::isValidNcName($prefix)) {
            throw new \ValueError(sprintf(
                'XMLWriter::writeAttributeNS(): Argument #1 ($prefix) must be a valid namespace prefix, %s given',
                var_export($prefix, true)
            ));
        }
        self::endOpenAttributeIfNeeded($state);
        $qname = self::composeQName($prefix, $name);
        $state->buffer .= ' '.self::escapeElementName($qname).'="'.self::escapeAttribute($content).'"';
        // null uri → no xmlns; empty string still emits xmlns:prefix="" (Zend/libxml).
        if (null !== $uri) {
            $state->pendingNsDecls[] = ['prefix' => $prefix, 'uri' => $uri];
        }

        return true;
    }

    /**
     * XMLWriter::startElementNS — open a namespaced element.
     * php-src: zim_XMLWriter_startElementNS / xmlTextWriterStartElementNS (#19446).
     */
    public static function startElementNS(
        ObjectEntry $entry,
        ?string $prefix,
        string $name,
        ?string $uri
    ): bool {
        $state = self::requireOpen($entry, 'XMLWriter::startElementNS()');
        if (!self::isValidElementName($name)) {
            throw new \ValueError(sprintf(
                'XMLWriter::startElementNS(): Argument #2 ($name) must be a valid element name, %s given',
                var_export($name, true)
            ));
        }
        if (null !== $prefix && '' !== $prefix && !self::isValidNcName($prefix)) {
            throw new \ValueError(sprintf(
                'XMLWriter::startElementNS(): Argument #1 ($prefix) must be a valid namespace prefix, %s given',
                var_export($prefix, true)
            ));
        }
        self::closeStartTagIfOpen($state);
        if ([] !== $state->elementStack) {
            if ($state->indent) {
                $parentIdx = \count($state->elementStack) - 1;
                $state->elementStack[$parentIdx]['hasIndentedChild'] = true;
                $state->buffer .= "\n".str_repeat($state->indentString, \count($state->elementStack));
            }
        }
        $qname = self::composeQName($prefix, $name);
        $state->elementStack[] = ['name' => $qname, 'hasIndentedChild' => false];
        $state->buffer .= '<'.self::escapeElementName($qname);
        if (null !== $uri) {
            $state->buffer .= self::xmlnsAttribute($prefix, $uri);
        }
        $state->startTagOpen = true;

        return true;
    }

    /**
     * XMLWriter::startAttributeNS — open a namespaced streaming attribute.
     * php-src: zim_XMLWriter_startAttributeNS / xmlTextWriterStartAttributeNS (#19446).
     */
    public static function startAttributeNS(
        ObjectEntry $entry,
        ?string $prefix,
        string $name,
        ?string $uri
    ): bool {
        $state = self::requireOpen($entry, 'XMLWriter::startAttributeNS()');
        if (!$state->startTagOpen || [] === $state->elementStack) {
            return false;
        }
        if (!self::isValidAttributeName($name)) {
            throw new \ValueError(sprintf(
                'XMLWriter::startAttributeNS(): Argument #2 ($name) must be a valid attribute name, %s given',
                var_export($name, true)
            ));
        }
        if (null !== $prefix && '' !== $prefix && !self::isValidNcName($prefix)) {
            throw new \ValueError(sprintf(
                'XMLWriter::startAttributeNS(): Argument #1 ($prefix) must be a valid namespace prefix, %s given',
                var_export($prefix, true)
            ));
        }
        self::endOpenAttributeIfNeeded($state);
        $qname = self::composeQName($prefix, $name);
        $state->buffer .= ' '.self::escapeElementName($qname).'="';
        $state->attributeOpen = true;
        if (null !== $uri) {
            $state->pendingNsDecls[] = ['prefix' => $prefix, 'uri' => $uri];
        }

        return true;
    }

    /**
     * Begin a streaming attribute (` name="`); content via text(); close with endAttribute().
     * php-src: zim_XMLWriter_startAttribute / xmlTextWriterStartAttribute (#19820).
     */
    public static function startAttribute(ObjectEntry $entry, string $name): bool
    {
        $state = self::requireOpen($entry, 'XMLWriter::startAttribute()');
        if (!$state->startTagOpen || [] === $state->elementStack) {
            return false;
        }
        if (!self::isValidAttributeName($name)) {
            throw new \ValueError(sprintf(
                'XMLWriter::startAttribute(): Argument #1 ($name) must be a valid attribute name, %s given',
                var_export($name, true)
            ));
        }
        self::endOpenAttributeIfNeeded($state);
        $state->buffer .= ' '.self::escapeElementName($name).'="';
        $state->attributeOpen = true;

        return true;
    }

    /**
     * Close a streaming attribute opened by startAttribute().
     * php-src: zim_XMLWriter_endAttribute / xmlTextWriterEndAttribute (#19820).
     */
    public static function endAttribute(ObjectEntry $entry): bool
    {
        $state = self::requireOpen($entry, 'XMLWriter::endAttribute()');
        if (!$state->attributeOpen) {
            return false;
        }
        $state->buffer .= '"';
        $state->attributeOpen = false;

        return true;
    }

    public static function writeElement(ObjectEntry $entry, string $name, ?string $content = null): bool
    {
        if (!self::startElement($entry, $name)) {
            return false;
        }
        if (null !== $content) {
            if (!self::text($entry, $content)) {
                return false;
            }
        }

        return self::endElement($entry);
    }

    /**
     * XMLWriter::writeElementNS — namespaced element with optional content.
     * php-src: zim_XMLWriter_writeElementNS / xmlTextWriterWriteElementNS (#19371).
     */
    public static function writeElementNS(
        ObjectEntry $entry,
        ?string $prefix,
        string $name,
        ?string $uri,
        ?string $content = null
    ): bool {
        $state = self::requireOpen($entry, 'XMLWriter::writeElementNS()');
        if (!self::isValidElementName($name)) {
            throw new \ValueError(sprintf(
                'XMLWriter::writeElementNS(): Argument #2 ($name) must be a valid element name, %s given',
                var_export($name, true)
            ));
        }
        if (null !== $prefix && '' !== $prefix && !self::isValidNcName($prefix)) {
            throw new \ValueError(sprintf(
                'XMLWriter::writeElementNS(): Argument #1 ($prefix) must be a valid namespace prefix, %s given',
                var_export($prefix, true)
            ));
        }
        self::closeStartTagIfOpen($state);
        if ([] !== $state->elementStack) {
            if ($state->indent) {
                $parentIdx = \count($state->elementStack) - 1;
                $state->elementStack[$parentIdx]['hasIndentedChild'] = true;
                $state->buffer .= "\n".str_repeat($state->indentString, \count($state->elementStack));
            }
        }
        $qname = self::composeQName($prefix, $name);
        $state->elementStack[] = ['name' => $qname, 'hasIndentedChild' => false];
        $state->buffer .= '<'.self::escapeElementName($qname);
        if (null !== $uri) {
            $state->buffer .= self::xmlnsAttribute($prefix, $uri);
        }
        $state->startTagOpen = true;
        if (null !== $content) {
            if (!self::text($entry, $content)) {
                return false;
            }
        }

        return self::endElement($entry);
    }

    /**
     * XMLWriter::writePI — one-shot processing instruction (php-src zim_XMLWriter_writePI; #19371).
     */
    public static function writePI(ObjectEntry $entry, string $target, string $content): bool
    {
        $state = self::requireOpen($entry, 'XMLWriter::writePI()');
        if ('' === $target) {
            throw new \ValueError(
                'XMLWriter::writePi(): Argument #2 ($content) must be a valid PI target, "" given'
            );
        }
        // libxml reserves xml / XmL / … for the XML declaration (php-src xmlTextWriterStartPI).
        if (0 === strcasecmp($target, 'xml')) {
            @\trigger_error(
                'XMLWriter::writePi(): xmlTextWriterStartPI : target name [Xx][Mm][Ll] is reserved for xml standardization!',
                \E_USER_WARNING
            );

            return false;
        }
        if (!self::isValidPiTarget($target)) {
            throw new \ValueError(sprintf(
                'XMLWriter::writePi(): Argument #1 ($target) must be a valid PI target, %s given',
                var_export($target, true)
            ));
        }
        if ($state->inCdata) {
            return false;
        }
        self::closeStartTagIfOpen($state);
        $state->buffer .= '<?'.self::escapeElementName($target).' '.$content.'?>';

        return true;
    }

    /**
     * XMLWriter::writeRaw — append unescaped markup (php-src zim_XMLWriter_writeRaw; #19371).
     */
    public static function writeRaw(ObjectEntry $entry, string $content): bool
    {
        $state = self::requireOpen($entry, 'XMLWriter::writeRaw()');
        self::closeStartTagIfOpen($state);
        $state->buffer .= $content;

        return true;
    }

    public static function writeCData(ObjectEntry $entry, string $content): bool
    {
        $state = self::requireOpen($entry, 'XMLWriter::writeCData()');
        self::closeStartTagIfOpen($state);
        $state->buffer .= '<![CDATA['.$content.']]>';

        return true;
    }

    /**
     * XMLWriter::startCData — open a CDATA section (php-src zim_XMLWriter_startCData; #19457).
     */
    public static function startCData(ObjectEntry $entry): bool
    {
        $state = self::requireOpen($entry, 'XMLWriter::startCData()');
        if ($state->inCdata) {
            @\trigger_error(
                'XMLWriter::startCdata(): xmlTextWriterStartCDATA : CDATA not allowed in this context!',
                \E_WARNING
            );

            return false;
        }
        self::closeStartTagIfOpen($state);
        $state->buffer .= '<![CDATA[';
        $state->inCdata = true;

        return true;
    }

    /**
     * XMLWriter::endCData — close a CDATA section (php-src zim_XMLWriter_endCData; #19457).
     */
    public static function endCData(ObjectEntry $entry): bool
    {
        $state = self::requireOpen($entry, 'XMLWriter::endCData()');
        if (!$state->inCdata) {
            return false;
        }
        $state->buffer .= ']]>';
        $state->inCdata = false;

        return true;
    }

    /**
     * XMLWriter::startPI — open a processing instruction (php-src zim_XMLWriter_startPI; #19457).
     */
    public static function startPI(ObjectEntry $entry, string $target): bool
    {
        $state = self::requireOpen($entry, 'XMLWriter::startPI()');
        if (!self::isValidPiTarget($target)) {
            throw new \ValueError(sprintf(
                'XMLWriter::startPi(): Argument #2 must be a valid PI target, %s given',
                var_export($target, true)
            ));
        }
        if ($state->inCdata) {
            return false;
        }
        self::closeStartTagIfOpen($state);
        $state->buffer .= '<?'.self::escapeElementName($target);
        $state->inPi = true;
        $state->piHasContent = false;

        return true;
    }

    /**
     * XMLWriter::endPI — close a processing instruction (php-src zim_XMLWriter_endPI; #19457).
     * Zend returns true with no write when no PI is open.
     */
    public static function endPI(ObjectEntry $entry): bool
    {
        $state = self::requireOpen($entry, 'XMLWriter::endPI()');
        if (!$state->inPi) {
            return true;
        }
        $state->buffer .= '?>';
        $state->inPi = false;
        $state->piHasContent = false;

        return true;
    }

    public static function writeComment(ObjectEntry $entry, string $content): bool
    {
        $state = self::requireOpen($entry, 'XMLWriter::writeComment()');
        self::closeStartTagIfOpen($state);
        $state->buffer .= '<!--'.$content.'-->';

        return true;
    }

    /**
     * XMLWriter::startComment — open a comment (php-src zim_XMLWriter_startComment; #19386).
     */
    public static function startComment(ObjectEntry $entry): bool
    {
        $state = self::requireOpen($entry, 'XMLWriter::startComment()');
        if ($state->inComment || $state->inCdata || $state->inPi || $state->inDtd) {
            return false;
        }
        self::closeStartTagIfOpen($state);
        $state->buffer .= '<!--';
        $state->inComment = true;

        return true;
    }

    /**
     * XMLWriter::endComment — close a comment (php-src zim_XMLWriter_endComment; #19386).
     */
    public static function endComment(ObjectEntry $entry): bool
    {
        $state = self::requireOpen($entry, 'XMLWriter::endComment()');
        if (!$state->inComment) {
            return false;
        }
        $state->buffer .= '-->';
        $state->inComment = false;

        return true;
    }

    /**
     * XMLWriter::startDtd — open a DOCTYPE (php-src zim_XMLWriter_startDtd; #19386).
     */
    public static function startDtd(
        ObjectEntry $entry,
        string $qualifiedName,
        ?string $publicId = null,
        ?string $systemId = null
    ): bool {
        $state = self::requireOpen($entry, 'XMLWriter::startDtd()');
        if ('' === $qualifiedName || $state->inDtd || $state->inComment || $state->inCdata || $state->inPi) {
            return false;
        }
        if (null !== $publicId && (null === $systemId || '' === $systemId)) {
            // libxml: PUBLIC requires a system identifier (php-src xmlTextWriterStartDTD).
            self::closeStartTagIfOpen($state);
            $state->buffer .= '<!DOCTYPE '.self::escapeElementName($qualifiedName);
            @\trigger_error(
                'XMLWriter::startDtd(): xmlTextWriterStartDTD : system identifier needed!',
                \E_WARNING
            );

            return false;
        }
        self::closeStartTagIfOpen($state);
        $state->buffer .= '<!DOCTYPE '.self::escapeElementName($qualifiedName);
        if (null !== $publicId) {
            $state->buffer .= ' PUBLIC "'.self::escapeAttribute($publicId).'" "'.self::escapeAttribute((string) $systemId).'"';
        } elseif (null !== $systemId && '' !== $systemId) {
            $state->buffer .= ' SYSTEM "'.self::escapeAttribute($systemId).'"';
        }
        $state->inDtd = true;

        return true;
    }

    /**
     * XMLWriter::endDtd — close a DOCTYPE (php-src zim_XMLWriter_endDtd; #19386).
     * Zend returns true with no write when no DTD is open (same as endPI).
     */
    public static function endDtd(ObjectEntry $entry): bool
    {
        $state = self::requireOpen($entry, 'XMLWriter::endDtd()');
        if (!$state->inDtd) {
            return true;
        }
        $state->buffer .= '>';
        $state->inDtd = false;

        return true;
    }

    /**
     * XMLWriter::writeDtd — one-shot DOCTYPE (php-src zim_XMLWriter_writeDtd; #19386).
     * Parameter name `$content` matches the stub (internal subset).
     */
    public static function writeDtd(
        ObjectEntry $entry,
        string $name,
        ?string $publicId = null,
        ?string $systemId = null,
        ?string $content = null
    ): bool {
        $state = self::requireOpen($entry, 'XMLWriter::writeDtd()');
        if ('' === $name || $state->inDtd || $state->inComment || $state->inCdata || $state->inPi) {
            return false;
        }
        if (null !== $publicId && (null === $systemId || '' === $systemId)) {
            self::closeStartTagIfOpen($state);
            $state->buffer .= '<!DOCTYPE '.self::escapeElementName($name);
            @\trigger_error(
                'XMLWriter::writeDtd(): xmlTextWriterStartDTD : system identifier needed!',
                \E_WARNING
            );

            return false;
        }
        self::closeStartTagIfOpen($state);
        $state->buffer .= '<!DOCTYPE '.self::escapeElementName($name);
        if (null !== $publicId) {
            $state->buffer .= ' PUBLIC "'.self::escapeAttribute($publicId).'" "'.self::escapeAttribute((string) $systemId).'"';
        } elseif (null !== $systemId && '' !== $systemId) {
            $state->buffer .= ' SYSTEM "'.self::escapeAttribute($systemId).'"';
        }
        if (null !== $content) {
            $state->buffer .= ' ['.$content.']';
        }
        $state->buffer .= '>';

        return true;
    }

    public static function text(ObjectEntry $entry, string $content): bool
    {
        $state = self::requireOpen($entry, 'XMLWriter::text()');
        if ($state->attributeOpen) {
            $state->buffer .= self::escapeAttribute($content);

            return true;
        }
        if ($state->inCdata || $state->inComment) {
            $state->buffer .= $content;

            return true;
        }
        if ($state->inPi) {
            // libxml inserts a single space before the first text() of a PI (#19457).
            if (!$state->piHasContent) {
                $state->buffer .= ' ';
                $state->piHasContent = true;
            }
            $state->buffer .= $content;

            return true;
        }
        self::closeStartTagIfOpen($state);
        $state->buffer .= self::escapeText($content);

        return true;
    }

    public static function endElement(ObjectEntry $entry): bool
    {
        $state = self::requireOpen($entry, 'XMLWriter::endElement()');
        if ([] === $state->elementStack) {
            return false;
        }
        if ($state->startTagOpen) {
            self::endOpenAttributeIfNeeded($state);
            foreach ($state->pendingNsDecls as $decl) {
                $state->buffer .= self::xmlnsAttribute($decl['prefix'], $decl['uri']);
            }
            $state->pendingNsDecls = [];
            $state->buffer .= '/>';
            array_pop($state->elementStack);
            $state->startTagOpen = false;

            return true;
        }

        return self::writeFullEndElement($state);
    }

    /**
     * Always write an explicit end tag (`</name>`), never a self-closing empty element.
     * php-src: zim_XMLWriter_fullEndElement / xmlTextWriterFullEndElement (#19551).
     */
    public static function fullEndElement(ObjectEntry $entry): bool
    {
        $state = self::requireOpen($entry, 'XMLWriter::fullEndElement()');
        if ([] === $state->elementStack) {
            return false;
        }
        if ($state->startTagOpen) {
            self::endOpenAttributeIfNeeded($state);
            foreach ($state->pendingNsDecls as $decl) {
                $state->buffer .= self::xmlnsAttribute($decl['prefix'], $decl['uri']);
            }
            $state->pendingNsDecls = [];
            $state->buffer .= '>';
            $state->startTagOpen = false;
        }

        return self::writeFullEndElement($state);
    }

    private static function writeFullEndElement(XmlWriterState $state): bool
    {
        $frame = array_pop($state->elementStack);
        if ($frame['hasIndentedChild']) {
            $state->buffer .= "\n".str_repeat($state->indentString, \count($state->elementStack));
            $state->buffer .= '</'.self::escapeElementName($frame['name']).'>'."\n";
        } else {
            $state->buffer .= '</'.self::escapeElementName($frame['name']).'>';
        }

        return true;
    }

    public static function endDocument(ObjectEntry $entry): bool
    {
        $state = self::requireOpen($entry, 'XMLWriter::endDocument()');
        while ([] !== $state->elementStack) {
            self::endElement($entry);
        }
        // php-src/libxml xmlTextWriterEndDocument appends a trailing newline.
        if (!str_ends_with($state->buffer, "\n")) {
            $state->buffer .= "\n";
        }
        if ('uri' === $state->mode && null !== $state->uri) {
            if (false === @file_put_contents($state->uri, $state->buffer)) {
                return false;
            }
        }
        if ('stream' === $state->mode && null !== $state->streamHandle && '' !== $state->buffer) {
            if (false === VmFs::fwrite($state->streamHandle, $state->buffer)) {
                return false;
            }
            $state->buffer = '';
        }

        return true;
    }

    public static function outputMemory(ObjectEntry $entry, bool $flush = true): string
    {
        $state = self::requireOpen($entry, 'XMLWriter::outputMemory()');
        if ('memory' !== $state->mode) {
            return '';
        }
        $out = $state->buffer;
        if ($flush) {
            $state->buffer = '';
        }

        return $out;
    }

    /**
     * XMLWriter::flush — memory returns buffer string; URI returns bytes written (php-src php_xmlwriter_flush; #19385).
     * PHP 8.0+ never returns false.
     *
     * @return string|int
     */
    public static function flush(ObjectEntry $entry, bool $empty = true): string|int
    {
        $state = self::requireOpen($entry, 'XMLWriter::flush()');
        if ('memory' === $state->mode) {
            $out = $state->buffer;
            if ($empty) {
                $state->buffer = '';
            }

            return $out;
        }
        if ('stream' === $state->mode && null !== $state->streamHandle) {
            if ('' === $state->buffer) {
                return 0;
            }
            $written = VmFs::fwrite($state->streamHandle, $state->buffer);
            if (false === $written) {
                return 0;
            }
            $state->buffer = '';

            return $written;
        }
        if (null === $state->uri) {
            return 0;
        }
        if ('' === $state->buffer) {
            return 0;
        }
        $written = @file_put_contents($state->uri, $state->buffer, \FILE_APPEND);
        if (false === $written) {
            return 0;
        }
        $state->buffer = '';

        return $written;
    }

    private static function resetState(XmlWriterState $state, string $mode, ?string $uri): void
    {
        $state->open = true;
        $state->mode = $mode;
        $state->uri = $uri;
        $state->streamHandle = null;
        $state->buffer = '';
        $state->elementStack = [];
        $state->startTagOpen = false;
        $state->attributeOpen = false;
        $state->documentStarted = false;
        $state->version = null;
        $state->encoding = null;
        // indent flags persist across openMemory in Zend? Reset for clean writers.
        $state->indent = false;
        $state->indentString = ' ';
        $state->inCdata = false;
        $state->inPi = false;
        $state->piHasContent = false;
        $state->inComment = false;
        $state->inDtd = false;
        $state->pendingNsDecls = [];
    }

    private static function requireOpen(ObjectEntry $entry, string $label): XmlWriterState
    {
        if (!XmlWriterRegistry::has($entry)) {
            throw new \Error('Invalid or uninitialized XMLWriter object');
        }
        $state = XmlWriterRegistry::state($entry);
        if (!$state->open) {
            throw new \Error('Invalid or uninitialized XMLWriter object');
        }

        return $state;
    }

    private static function endOpenAttributeIfNeeded(XmlWriterState $state): void
    {
        if ($state->attributeOpen) {
            $state->buffer .= '"';
            $state->attributeOpen = false;
        }
    }

    private static function closeStartTagIfOpen(XmlWriterState $state): void
    {
        self::endOpenAttributeIfNeeded($state);
        if ($state->startTagOpen) {
            foreach ($state->pendingNsDecls as $decl) {
                $state->buffer .= self::xmlnsAttribute($decl['prefix'], $decl['uri']);
            }
            $state->pendingNsDecls = [];
            $state->buffer .= '>';
            $state->startTagOpen = false;
        }
    }

    private static function isValidElementName(string $name): bool
    {
        if ('' === $name) {
            return false;
        }
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9._:-]*$/', $name)) {
            return false;
        }

        return true;
    }

    private static function isValidAttributeName(string $name): bool
    {
        return self::isValidElementName($name);
    }

    /** NCName for namespace prefixes (no colon). */
    private static function isValidNcName(string $name): bool
    {
        if ('' === $name) {
            return false;
        }

        return (bool) preg_match('/^[A-Za-z_][A-Za-z0-9._-]*$/', $name);
    }

    private static function composeQName(?string $prefix, string $localName): string
    {
        if (null === $prefix) {
            return $localName;
        }

        return $prefix.':'.$localName;
    }

    private static function xmlnsAttribute(?string $prefix, string $uri): string
    {
        if (null === $prefix) {
            return ' xmlns="'.self::escapeAttribute($uri).'"';
        }

        return ' xmlns:'.$prefix.'="'.self::escapeAttribute($uri).'"';
    }

    private static function isValidPiTarget(string $target): bool
    {
        if ('' === $target) {
            return false;
        }
        // XML Name without spaces (php-src / libxml PI target check).
        return (bool) preg_match('/^[A-Za-z_][A-Za-z0-9._-]*$/', $target);
    }

    private static function escapeElementName(string $name): string
    {
        return $name;
    }

    private static function escapeAttribute(string $value): string
    {
        return str_replace(
            ['&', '"', '<'],
            ['&amp;', '&quot;', '&lt;'],
            $value
        );
    }

    private static function escapeText(string $value): string
    {
        return str_replace(
            ['&', '<', '>'],
            ['&amp;', '&lt;', '&gt;'],
            $value
        );
    }

    public static function typeLabel(Variable $var): string
    {
        return match ($var->type) {
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_BOOL => 'bool',
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_DOUBLE => 'float',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => $var->toObject()->class->name,
            default => 'mixed',
        };
    }
}
