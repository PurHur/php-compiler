<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlreader;

/** One XMLReader::read() node snapshot. */
final class XmlReaderEvent
{
    public function __construct(
        public int $nodeType,
        public string $name,
        public string $value,
        /** @var array<string, string> */
        public array $attributes,
        public int $depth,
        public bool $isEmptyElement,
        public bool $hasValue,
        public bool $hasAttributes,
        public int $attributeCount,
        public string $localName,
        public string $prefix,
        public string $namespaceUri,
    ) {
    }
}
