<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

/** Per-instance XMLWriter buffer state (#6065, #19340). */
final class XmlWriterState
{
    public bool $open = false;

    /** @var ''|'memory'|'uri' */
    public string $mode = '';

    public ?string $uri = null;

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

    /**
     * Namespace decls from writeAttributeNS, flushed when the start tag closes
     * (php-src/libxml defers xmlns onto the open element; #19371).
     *
     * @var list<array{prefix: ?string, uri: string}>
     */
    public array $pendingNsDecls = [];
}
