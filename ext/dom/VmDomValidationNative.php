<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

/**
 * libxml2 XSD / RelaxNG validation via FFI (php-src ext/dom/document.c; #18806).
 *
 * Host PHP only: {@see extension_loaded}('ffi') — same pattern as {@see VmOpensslCipherNative}.
 */
final class VmDomValidationNative
{
    private const LIBXML_DTDLOAD = 2;
    private const LIBXML_DTDVALID = 4;

    /** @var \FFI|null */
    private static $ffi = null;

    private static bool $ffiUnavailable = false;

    /** @var list<array{level: int, code: int, column: int, message: string, file: string, line: int}> */
    private static array $lastErrors = [];

    /**
     * Whether the last validate* call successfully parsed the schema/RNG resource itself
     * (before document validation). Used so entity-loader paths can emit php-src's
     * "Failed to parse the XML resource" / "xmlRelaxNGParse: could not load" only on
     * schema parse failure (#29596).
     */
    private static ?bool $lastSchemaResourceParsed = null;

    public static function available(): bool
    {
        return null !== self::ffi();
    }

    /**
     * @return list<array{level: int, code: int, column: int, message: string, file: string, line: int}>
     */
    public static function consumeLastErrors(): array
    {
        $errors = self::$lastErrors;
        self::$lastErrors = [];

        return $errors;
    }

    /** @see $lastSchemaResourceParsed */
    public static function lastSchemaResourceParsed(): ?bool
    {
        return self::$lastSchemaResourceParsed;
    }

    public static function validateSchemaDocument(string $docXml, string $schemaPath): bool
    {
        return self::validateSchemaAgainstDoc($docXml, static function ($ffi) use ($schemaPath) {
            return $ffi->xmlSchemaNewParserCtxt($schemaPath);
        });
    }

    /**
     * Parse an XSD file without validating a document (XMLReader::setSchema attach; #19553).
     */
    public static function parseSchemaFile(string $schemaPath): bool
    {
        self::$lastErrors = [];
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        $prev = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $parser = $ffi->xmlSchemaNewParserCtxt($schemaPath);
            if (null === $parser) {
                self::captureLibxmlErrors();

                return false;
            }
            $schema = $ffi->xmlSchemaParse($parser);
            $ffi->xmlSchemaFreeParserCtxt($parser);
            if (null === $schema) {
                self::captureLibxmlErrors();

                return false;
            }
            $ffi->xmlSchemaFree($schema);

            return true;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
    }

    /**
     * Parse a RelaxNG file without validating a document (XMLReader::setRelaxNGSchema; #19553).
     */
    public static function parseRelaxNGFile(string $rngPath): bool
    {
        self::$lastErrors = [];
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        $prev = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $parser = $ffi->xmlRelaxNGNewParserCtxt($rngPath);
            if (null === $parser) {
                self::captureLibxmlErrors();

                return false;
            }
            $grammar = $ffi->xmlRelaxNGParse($parser);
            $ffi->xmlRelaxNGFreeParserCtxt($parser);
            if (null === $grammar) {
                self::captureLibxmlErrors();

                return false;
            }
            $ffi->xmlRelaxNGFree($grammar);

            return true;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
    }

    /**
     * Parse an in-memory RelaxNG grammar (XMLReader::setRelaxNGSchemaSource; #19940).
     */
    public static function parseRelaxNGSource(string $rngSource): bool
    {
        self::$lastErrors = [];
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        $prev = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $parser = $ffi->xmlRelaxNGNewMemParserCtxt($rngSource, \strlen($rngSource));
            if (null === $parser) {
                self::captureLibxmlErrors();

                return false;
            }
            $grammar = $ffi->xmlRelaxNGParse($parser);
            $ffi->xmlRelaxNGFreeParserCtxt($parser);
            if (null === $grammar) {
                self::captureLibxmlErrors();

                return false;
            }
            $ffi->xmlRelaxNGFree($grammar);

            return true;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
    }

    /**
     * In-memory XSD validation (php-src dom_document_schema_validate_source / xmlSchemaNewMemParserCtxt; #19419).
     */
    public static function validateSchemaDocumentSource(string $docXml, string $schemaSource): bool
    {
        return self::validateSchemaAgainstDoc($docXml, static function ($ffi) use ($schemaSource) {
            return $ffi->xmlSchemaNewMemParserCtxt($schemaSource, \strlen($schemaSource));
        });
    }

    /**
     * @param callable(\FFI): mixed $newParserCtxt returns xmlSchemaParserCtxt*|null
     */
    private static function validateSchemaAgainstDoc(string $docXml, callable $newParserCtxt): bool
    {
        self::$lastErrors = [];
        self::$lastSchemaResourceParsed = null;
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        $prev = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $doc = $ffi->xmlReadMemory($docXml, \strlen($docXml), '', null, 0);
            if (null === $doc) {
                self::captureLibxmlErrors();

                return false;
            }

            $parser = $newParserCtxt($ffi);
            if (null === $parser) {
                self::$lastSchemaResourceParsed = false;
                self::captureLibxmlErrors();
                $ffi->xmlFreeDoc($doc);

                return false;
            }

            $schema = $ffi->xmlSchemaParse($parser);
            $ffi->xmlSchemaFreeParserCtxt($parser);
            if (null === $schema) {
                self::$lastSchemaResourceParsed = false;
                self::captureLibxmlErrors();
                $ffi->xmlFreeDoc($doc);

                return false;
            }
            self::$lastSchemaResourceParsed = true;

            $valid = $ffi->xmlSchemaNewValidCtxt($schema);
            if (null === $valid) {
                $ffi->xmlSchemaFree($schema);
                $ffi->xmlFreeDoc($doc);
                self::captureLibxmlErrors();

                return false;
            }

            $rc = (int) $ffi->xmlSchemaValidateDoc($valid, $doc);
            if (0 !== $rc) {
                self::captureLibxmlErrors();
            }

            $ffi->xmlSchemaFreeValidCtxt($valid);
            $ffi->xmlSchemaFree($schema);
            $ffi->xmlFreeDoc($doc);

            return 0 === $rc;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
    }

    /**
     * @return array{valid: bool, errors: list<array{level: int, code: int, column: int, message: string, file: string, line: int}>}
     */
    public static function validateDtdDocument(string $docXml): array
    {
        self::$lastErrors = [];
        $ffi = self::ffi();
        if (null === $ffi) {
            return ['valid' => false, 'errors' => []];
        }

        $prev = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $doc = $ffi->xmlReadMemory($docXml, \strlen($docXml), '', null, self::LIBXML_DTDLOAD | self::LIBXML_DTDVALID);
            if (null === $doc) {
                self::captureLibxmlErrors();

                return ['valid' => false, 'errors' => self::consumeLastErrors()];
            }

            $validCtxt = $ffi->xmlNewValidCtxt();
            if (null === $validCtxt) {
                self::captureLibxmlErrors();
                $ffi->xmlFreeDoc($doc);

                return ['valid' => false, 'errors' => self::consumeLastErrors()];
            }

            $rc = (int) $ffi->xmlValidateDocument($validCtxt, $doc);
            if (1 !== $rc) {
                self::captureLibxmlErrors();
            }

            $ffi->xmlFreeValidCtxt($validCtxt);
            $ffi->xmlFreeDoc($doc);

            return ['valid' => 1 === $rc, 'errors' => self::consumeLastErrors()];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
    }

    public static function validateRelaxNGDocument(string $docXml, string $rngPath): bool
    {
        return self::validateRelaxNGAgainstDoc($docXml, static function ($ffi) use ($rngPath) {
            return $ffi->xmlRelaxNGNewParserCtxt($rngPath);
        });
    }

    /**
     * In-memory RelaxNG validation (XMLReader::setRelaxNGSchemaSource; #19940).
     */
    public static function validateRelaxNGDocumentSource(string $docXml, string $rngSource): bool
    {
        return self::validateRelaxNGAgainstDoc($docXml, static function ($ffi) use ($rngSource) {
            return $ffi->xmlRelaxNGNewMemParserCtxt($rngSource, \strlen($rngSource));
        });
    }

    /**
     * @param callable(\FFI): mixed $newParserCtxt returns xmlRelaxNGParserCtxt*|null
     */
    private static function validateRelaxNGAgainstDoc(string $docXml, callable $newParserCtxt): bool
    {
        self::$lastErrors = [];
        self::$lastSchemaResourceParsed = null;
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        $prev = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $doc = $ffi->xmlReadMemory($docXml, \strlen($docXml), '', null, 0);
            if (null === $doc) {
                self::captureLibxmlErrors();

                return false;
            }

            $parser = $newParserCtxt($ffi);
            if (null === $parser) {
                self::$lastSchemaResourceParsed = false;
                self::captureLibxmlErrors();
                $ffi->xmlFreeDoc($doc);

                return false;
            }

            $grammar = $ffi->xmlRelaxNGParse($parser);
            $ffi->xmlRelaxNGFreeParserCtxt($parser);
            if (null === $grammar) {
                self::$lastSchemaResourceParsed = false;
                self::captureLibxmlErrors();
                $ffi->xmlFreeDoc($doc);

                return false;
            }
            self::$lastSchemaResourceParsed = true;

            $valid = $ffi->xmlRelaxNGNewValidCtxt($grammar);
            if (null === $valid) {
                $ffi->xmlRelaxNGFree($grammar);
                $ffi->xmlFreeDoc($doc);
                self::captureLibxmlErrors();

                return false;
            }

            $rc = (int) $ffi->xmlRelaxNGValidateDoc($valid, $doc);
            if (0 !== $rc) {
                self::captureLibxmlErrors();
            }

            $ffi->xmlRelaxNGFreeValidCtxt($valid);
            $ffi->xmlRelaxNGFree($grammar);
            $ffi->xmlFreeDoc($doc);

            return 0 === $rc;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
    }

    private static function captureLibxmlErrors(): void
    {
        $errors = libxml_get_errors();
        foreach ($errors as $error) {
            $message = trim($error->message);
            if ('' === $message) {
                continue;
            }
            self::$lastErrors[] = [
                'level' => (int) $error->level,
                'code' => (int) $error->code,
                'column' => (int) $error->column,
                'message' => $message,
                'file' => (string) $error->file,
                'line' => (int) $error->line,
            ];
        }
        libxml_clear_errors();
    }

    /** @return \FFI|null */
    private static function ffi()
    {
        if (self::$ffiUnavailable) {
            return null;
        }
        if (null !== self::$ffi) {
            return self::$ffi;
        }
        if (!\extension_loaded('ffi')) {
            self::$ffiUnavailable = true;

            return null;
        }

        $cdef = <<<'CDEF'
typedef struct _xmlDoc xmlDoc;
typedef struct _xmlValidCtxt xmlValidCtxt;
typedef struct _xmlSchema xmlSchema;
typedef struct _xmlSchemaParserCtxt xmlSchemaParserCtxt;
typedef struct _xmlSchemaValidCtxt xmlSchemaValidCtxt;
typedef struct _xmlRelaxNG xmlRelaxNG;
typedef struct _xmlRelaxNGParserCtxt xmlRelaxNGParserCtxt;
typedef struct _xmlRelaxNGValidCtxt xmlRelaxNGValidCtxt;

xmlDoc* xmlReadMemory(const char* buffer, int size, const char* URL, const char* encoding, int options);
void xmlFreeDoc(xmlDoc* cur);

xmlValidCtxt* xmlNewValidCtxt(void);
void xmlFreeValidCtxt(xmlValidCtxt* cur);
int xmlValidateDocument(xmlValidCtxt* ctxt, xmlDoc* doc);

xmlSchemaParserCtxt* xmlSchemaNewParserCtxt(const char* URL);
xmlSchemaParserCtxt* xmlSchemaNewMemParserCtxt(const char* buffer, int size);
xmlSchema* xmlSchemaParse(xmlSchemaParserCtxt* ctxt);
void xmlSchemaFreeParserCtxt(xmlSchemaParserCtxt* ctxt);
void xmlSchemaFree(xmlSchema* schema);
xmlSchemaValidCtxt* xmlSchemaNewValidCtxt(xmlSchema* schema);
int xmlSchemaValidateDoc(xmlSchemaValidCtxt* ctxt, xmlDoc* doc);
void xmlSchemaFreeValidCtxt(xmlSchemaValidCtxt* ctxt);

xmlRelaxNGParserCtxt* xmlRelaxNGNewParserCtxt(const char* URL);
xmlRelaxNGParserCtxt* xmlRelaxNGNewMemParserCtxt(const char* buffer, int size);
xmlRelaxNG* xmlRelaxNGParse(xmlRelaxNGParserCtxt* ctxt);
void xmlRelaxNGFreeParserCtxt(xmlRelaxNGParserCtxt* ctxt);
void xmlRelaxNGFree(xmlRelaxNG* schema);
xmlRelaxNGValidCtxt* xmlRelaxNGNewValidCtxt(xmlRelaxNG* schema);
int xmlRelaxNGValidateDoc(xmlRelaxNGValidCtxt* ctxt, xmlDoc* doc);
void xmlRelaxNGFreeValidCtxt(xmlRelaxNGValidCtxt* ctxt);
CDEF;

        foreach (['libxml2.so.2', 'libxml2.so'] as $lib) {
            try {
                self::$ffi = \FFI::cdef($cdef, $lib);

                return self::$ffi;
            } catch (\Throwable) {
            }
        }

        self::$ffiUnavailable = true;

        return null;
    }
}
