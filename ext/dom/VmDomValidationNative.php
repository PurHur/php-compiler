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
    /** @var \FFI|null */
    private static $ffi = null;

    private static bool $ffiUnavailable = false;

    /** @var list<string> */
    private static array $lastErrors = [];

    public static function available(): bool
    {
        return null !== self::ffi();
    }

    /** @return list<string> */
    public static function consumeLastErrors(): array
    {
        $errors = self::$lastErrors;
        self::$lastErrors = [];

        return $errors;
    }

    public static function validateSchemaDocument(string $docXml, string $schemaPath): bool
    {
        self::$lastErrors = [];
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

            $parser = $ffi->xmlSchemaNewParserCtxt($schemaPath);
            if (null === $parser) {
                self::captureLibxmlErrors();
                $ffi->xmlFreeDoc($doc);

                return false;
            }

            $schema = $ffi->xmlSchemaParse($parser);
            $ffi->xmlSchemaFreeParserCtxt($parser);
            if (null === $schema) {
                self::captureLibxmlErrors();
                $ffi->xmlFreeDoc($doc);

                return false;
            }

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

    public static function validateRelaxNGDocument(string $docXml, string $rngPath): bool
    {
        self::$lastErrors = [];
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

            $parser = $ffi->xmlRelaxNGNewParserCtxt($rngPath);
            if (null === $parser) {
                self::captureLibxmlErrors();
                $ffi->xmlFreeDoc($doc);

                return false;
            }

            $grammar = $ffi->xmlRelaxNGParse($parser);
            $ffi->xmlRelaxNGFreeParserCtxt($parser);
            if (null === $grammar) {
                self::captureLibxmlErrors();
                $ffi->xmlFreeDoc($doc);

                return false;
            }

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
            if ('' !== $message) {
                self::$lastErrors[] = $message;
            }
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
typedef struct _xmlSchema xmlSchema;
typedef struct _xmlSchemaParserCtxt xmlSchemaParserCtxt;
typedef struct _xmlSchemaValidCtxt xmlSchemaValidCtxt;
typedef struct _xmlRelaxNG xmlRelaxNG;
typedef struct _xmlRelaxNGParserCtxt xmlRelaxNGParserCtxt;
typedef struct _xmlRelaxNGValidCtxt xmlRelaxNGValidCtxt;

xmlDoc* xmlReadMemory(const char* buffer, int size, const char* URL, const char* encoding, int options);
void xmlFreeDoc(xmlDoc* cur);

xmlSchemaParserCtxt* xmlSchemaNewParserCtxt(const char* URL);
xmlSchema* xmlSchemaParse(xmlSchemaParserCtxt* ctxt);
void xmlSchemaFreeParserCtxt(xmlSchemaParserCtxt* ctxt);
void xmlSchemaFree(xmlSchema* schema);
xmlSchemaValidCtxt* xmlSchemaNewValidCtxt(xmlSchema* schema);
int xmlSchemaValidateDoc(xmlSchemaValidCtxt* ctxt, xmlDoc* doc);
void xmlSchemaFreeValidCtxt(xmlSchemaValidCtxt* ctxt);

xmlRelaxNGParserCtxt* xmlRelaxNGNewParserCtxt(const char* URL);
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
