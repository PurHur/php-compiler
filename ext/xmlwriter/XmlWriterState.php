<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

/** Per-instance XMLWriter buffer state (#6065, #19340). */
final class XmlWriterState
{
    public bool $open = false;

    /** @var ''|'memory'|'uri'|'stream' */
    public string $mode = '';

    public ?string $uri = null;

    /** VM stream handle when {@see $mode} is `stream` (#19606). */
    public ?int $streamHandle = null;

    public string $buffer = '';

    /** @var list<array{name: string, hasIndentedChild: bool}> */
    public array $elementStack = [];

    /** True while the current element's start tag is still open (`<name` …). */
    public bool $startTagOpen = false;

    /** True while a streaming attribute is open (` name="` … pending `"`). */
    public bool $attributeOpen = false;

    public bool $documentStarted = false;

    public ?string $version = null;

    public ?string $encoding = null;

    public bool $indent = false;

    public string $indentString = ' ';

    /** Inside startCData() … endCData() (php-src xmlTextWriterStartCDATA; #19457). */
    public bool $inCdata = false;

    /** Inside startPI() … endPI() (php-src xmlTextWriterStartPI; #19457). */
    public bool $inPi = false;

    /** True after any text() inside the current PI (controls leading space before content). */
    public bool $piHasContent = false;

    /** Inside startComment() … endComment() (php-src xmlTextWriterStartComment; #19386). */
    public bool $inComment = false;

    /** Inside startDtd() … endDtd() (php-src xmlTextWriterStartDTD; #19386). */
    public bool $inDtd = false;

    /** True after `[` opened for the DOCTYPE internal subset (#19468). */
    public bool $dtdSubsetOpen = false;

    /** Inside startDtdEntity() … endDtdEntity() (php-src xmlTextWriterStartDTDEntity; #19468). */
    public bool $inDtdEntity = false;

    /** Parameter-entity (`%`) form for the open DTD entity (#19468). */
    public bool $dtdEntityIsParam = false;

    /** True after the opening ` "` of an internal entity value (#19468). */
    public bool $dtdEntityHasContent = false;

    /**
     * Namespace decls from writeAttributeNS, flushed when the start tag closes
     * (php-src/libxml defers xmlns onto the open element; #19371).
     *
     * @var list<array{prefix: ?string, uri: string}>
     */
    public array $pendingNsDecls = [];
}
