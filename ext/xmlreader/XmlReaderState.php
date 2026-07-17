<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlreader;

/** Pull-parser cursor over pre-tokenized reader events. */
final class XmlReaderState
{
    public string $uri = '';

    public bool $closed = false;

    public bool $valid = true;

    /** Raw document bytes for libxml-style read() diagnostics (#19933). */
    public string $sourceData = '';

    /**
     * Deferred libxml validation records when {@see $valid} is false (emitted on first read()).
     *
     * @var list<array{level: int, code: int, column: int, message: string, file: string, line: int}>
     */
    public array $parseErrorRecords = [];

    public bool $readParseErrorsEmitted = false;

    /** @var list<XmlReaderEvent> */
    public array $events = [];

    /** Index of last returned event; -1 before first read(). */
    public int $position = -1;

    public ?XmlReaderEvent $current = null;

    /**
     * Attribute cursor index into the current ELEMENT's attribute map (document order).
     * null = not on an attribute node (php-src moveToAttribute* / moveToElement).
     */
    public ?int $attributeIndex = null;

    /**
     * Parser properties (XMLReader::LOADDTD / DEFAULTATTRS / VALIDATE / SUBST_ENTITIES).
     *
     * @var array<int, bool>
     */
    public array $parserProps = [
        XmlReaderConstants::LOADDTD => false,
        XmlReaderConstants::DEFAULTATTRS => false,
        XmlReaderConstants::VALIDATE => false,
        XmlReaderConstants::SUBST_ENTITIES => false,
    ];

    /** XSD file path when {@see VmXmlReader::setSchema} attached a schema; null = none. */
    public ?string $schemaPath = null;

    /** RelaxNG file path when {@see VmXmlReader::setRelaxNGSchema} attached a grammar; null = none. */
    public ?string $relaxNgPath = null;

    /** In-memory RelaxNG source when {@see VmXmlReader::setRelaxNGSchemaSource} attached a grammar; null = none. */
    public ?string $relaxNgSource = null;

    /** True when an XSD or RelaxNG schema is attached (affects {@see VmXmlReader::isValid}). */
    public bool $schemaModeActive = false;

    /** Schema/DTD validity after the first read() under schema or VALIDATE mode. */
    public bool $schemaValid = true;

    /** Whether deferred schema/DTD validation has been applied. */
    public bool $schemaCheckDone = false;
}
