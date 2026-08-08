<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xml;

use PHPCompiler\ext\libxml\LibxmlConstants;
use PHPCompiler\Frame;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;

/**
 * Minimal expat-shaped parser for xml_parse() v1 (#3494, #6058).
 *
 * Parser diagnostics live on the parser resource; expat failures always populate
 * the libxml error ring (libxml_get_last_error) without php_error() (#18135, #18146).
 */
final class VmXml
{
    /** libxml/xmlerror.h — XML_ERR_TAG_NOT_FINISHED (php-src ext/libxml/libxml.c). */
    private const XML_ERR_TAG_NOT_FINISHED = 73;

    /** libxml/xmlerror.h — XML_ERR_UNCLOSED_NODE_TAG (php-src ext/libxml/libxml.c; #14467). */
    private const XML_ERR_UNCLOSED_NODE_TAG = 77;

    /** libxml/xmlerror.h — XML_ERR_TAG_NAME_MISMATCH (php-src ext/xml/xml.c; #18120). */
    private const XML_ERR_TAG_NAME_MISMATCH = 76;

    /** libxml/xmlerror.h — XML_ERR_NAME_REQUIRED (php-src ext/libxml / libxml2; #22655, re-#14467). */
    private const XML_ERR_NAME_REQUIRED = 68;

    /** libxml/xmlerror.h — XML_ERR_ENTITYREF_SEMICOL_MISSING (libxml2; #22774 / #22775). */
    private const XML_ERR_ENTITYREF_SEMICOL_MISSING = 23;

    /** libxml/xmlerror.h — XML_ERR_UNDECLARED_ENTITY (libxml2; #22774 / #22775). */
    private const XML_ERR_UNDECLARED_ENTITY = 26;

    /** @var array<int, array<string, mixed>> */
    private static array $parsers = [];

    public static function initParserState(int $parserId, bool $nsAware = false, string $nsSeparator = ':'): void
    {
        $state = XmlParserHandlers::defaultParserState();
        $state['nsAware'] = $nsAware;
        $state['nsSeparator'] = $nsSeparator;
        self::$parsers[$parserId] = $state;
    }

    /** @return null|array<string, mixed> */
    public static function parserState(int $parserId): ?array
    {
        return self::$parsers[$parserId] ?? null;
    }

    /** @param array<string, mixed> $state */
    public static function replaceParserState(int $parserId, array $state): void
    {
        self::$parsers[$parserId] = $state;
    }

    public static function hasParserState(int $parserId): bool
    {
        return isset(self::$parsers[$parserId]);
    }

    /**
     * xml_parser_free() — no-op since PHP 8.0 (php-src ext/xml/xml.c).
     *
     * XMLParser objects are GC-owned; freeing must not invalidate the handle so
     * later xml_parse()/xml_parser_free() still succeed (#22813).
     */
    public static function parserFree(int $parser): bool
    {
        return isset(self::$parsers[$parser]);
    }

    public static function getErrorCode(int $parser): int
    {
        if (!isset(self::$parsers[$parser])) {
            throw new \ValueError('xml_get_error_code(): Argument #1 ($parser) must be a valid XML parser');
        }

        return self::$parsers[$parser]['errorCode'];
    }

    public static function getCurrentLineNumber(int $parser): int
    {
        if (!isset(self::$parsers[$parser])) {
            throw new \ValueError('xml_get_current_line_number(): Argument #1 ($parser) must be a valid XML parser');
        }

        return self::$parsers[$parser]['line'];
    }

    public static function getCurrentColumnNumber(int $parser): int
    {
        if (!isset(self::$parsers[$parser])) {
            throw new \ValueError('xml_get_current_column_number(): Argument #1 ($parser) must be a valid XML parser');
        }

        return self::$parsers[$parser]['column'];
    }

    public static function getCurrentByteIndex(int $parser): int
    {
        if (!isset(self::$parsers[$parser])) {
            throw new \ValueError('xml_get_current_byte_index(): Argument #1 ($parser) must be a valid XML parser');
        }

        return self::$parsers[$parser]['byteIndex'];
    }

    public static function errorString(int $code): string
    {
        return self::ERROR_STRINGS[$code] ?? 'Unknown';
    }

    /** @var array<int, string> expat/libxml codes (php-src ext/xml/xml.c; #18120). */
    private const ERROR_STRINGS = [
        0 => 'No error',
        1 => 'No memory',
        2 => 'Invalid document start',
        3 => 'Empty document',
        4 => 'Not well-formed (invalid token)',
        5 => 'Invalid document end',
        6 => 'Invalid hexadecimal character reference',
        7 => 'Invalid decimal character reference',
        8 => 'Invalid character reference',
        9 => 'Invalid character',
        10 => 'XML_ERR_CHARREF_AT_EOF',
        11 => 'XML_ERR_CHARREF_IN_PROLOG',
        12 => 'XML_ERR_CHARREF_IN_EPILOG',
        13 => 'XML_ERR_CHARREF_IN_DTD',
        14 => 'XML_ERR_ENTITYREF_AT_EOF',
        15 => 'XML_ERR_ENTITYREF_IN_PROLOG',
        16 => 'XML_ERR_ENTITYREF_IN_EPILOG',
        17 => 'XML_ERR_ENTITYREF_IN_DTD',
        18 => 'PEReference at end of document',
        19 => 'PEReference in prolog',
        20 => 'PEReference in epilog',
        21 => 'PEReference: forbidden within markup decl in internal subset',
        self::XML_ERR_TAG_NOT_FINISHED => '> required',
        self::XML_ERR_TAG_NAME_MISMATCH => 'Mismatched tag',
        self::XML_ERR_UNCLOSED_NODE_TAG => 'Tag not finished',
    ];

    public static function parse(
        Context $ctx,
        int $parser,
        string $data,
        bool $isFinal,
        ?Frame $frame = null,
        ?ObjectEntry $parserObject = null
    ): int {
        if (!isset(self::$parsers[$parser])) {
            throw new \ValueError('xml_parse(): Argument #1 ($parser) must be a valid XML parser');
        }

        // php-src XML_Parse(parser, data, len, isFinal): non-final chunks still feed Expat
        // and may fire SAX handlers; only EOF incompleteness is deferred (#24647).
        $state = self::$parsers[$parser];
        if (!empty($state['finished'])) {
            // Expat returns 0 with no new error code after a prior isFinal document.
            return 0;
        }

        // Mark isparsing so XML_OPTION_PARSE_HUGE cannot change mid-parse (php-src; #28171).
        $state['isParsing'] = true;
        self::$parsers[$parser] = $state;

        try {
            return self::parseWhileParsing($ctx, $parser, $data, $isFinal, $frame, $parserObject);
        } finally {
            if (isset(self::$parsers[$parser])) {
                self::$parsers[$parser]['isParsing'] = false;
            }
        }
    }

    /**
     * Body of {@see parse()} while {@code isParsing} is true (#28171).
     */
    private static function parseWhileParsing(
        Context $ctx,
        int $parser,
        string $data,
        bool $isFinal,
        ?Frame $frame,
        ?ObjectEntry $parserObject
    ): int {
        $state = self::$parsers[$parser];
        $state['buffer'] = ($state['buffer'] ?? '').$data;
        self::$parsers[$parser] = $state;
        $accumulated = $state['buffer'];

        if ('' === $accumulated) {
            if (!$isFinal) {
                return 1;
            }
            $error = self::validateWellFormed($accumulated);
            if (null === $error) {
                self::$parsers[$parser]['finished'] = true;

                return 1;
            }
            $error = self::expatAdjustLibxmlError($error);
            self::recordParserError($parser, $error, $accumulated);
            \PHPCompiler\ext\libxml\VmLibxml::recordError($error);
            self::$parsers[$parser]['finished'] = true;

            return 0;
        }

        // Fire SAX as complete tokens arrive — do not wait for document well-formedness (#24657).
        if (null !== $parserObject) {
            $state = VmXmlSaxDispatcher::dispatchIncremental(
                $ctx,
                $parserObject,
                self::$parsers[$parser],
                $isFinal,
                $frame
            );
            self::$parsers[$parser] = $state;
        }

        $error = self::validateWellFormed($accumulated);
        if (null === $error) {
            self::recordSuccessfulParse($parser, $accumulated);
            $state = self::$parsers[$parser];
            // Fallback for parses with no handlers previously registered mid-stream, or
            // when incremental could not run (no parser object): full-document dispatch once.
            if (null !== $parserObject && empty($state['saxDispatched'])) {
                VmXmlSaxDispatcher::dispatch($ctx, $parserObject, $accumulated, $frame);
                $state['saxDispatched'] = true;
            }
            if ($isFinal) {
                $state['finished'] = true;
                $state['buffer'] = '';
            }
            self::$parsers[$parser] = $state;

            return 1;
        }

        // Incomplete token / unclosed root while more data may arrive — Expat returns success
        // with error code 0 until isFinal (php-src ext/xml/xml.c XML_Parse).
        if (!$isFinal && self::isRecoverableIncompleteError($error)) {
            // Expat still advances the parse cursor past complete tokens (#25817).
            self::recordExpatParseCursor($parser, $accumulated, true);

            return 1;
        }

        $error = self::expatAdjustLibxmlError($error);
        self::recordParserError($parser, $error, $accumulated);
        \PHPCompiler\ext\libxml\VmLibxml::recordError($error);
        if ($isFinal) {
            // Do not clobber diagnostics recorded above with a stale $state copy.
            self::$parsers[$parser]['finished'] = true;
        }

        return 0;
    }

    /**
     * Errors that mean "need more input" rather than a hard well-formedness failure.
     *
     * @param array{level: int, code: int, column: int, message: string, file: string, line: int, byteIndex?: int} $error
     */
    private static function isRecoverableIncompleteError(array $error): bool
    {
        $code = $error['code'];

        return self::XML_ERR_TAG_NOT_FINISHED === $code
            || self::XML_ERR_UNCLOSED_NODE_TAG === $code
            || self::XML_ERR_NAME_REQUIRED === $code
            || 4 === $code; // empty / not-well-formed token at EOF — deferred until isFinal
    }

    private static function clearParserDiagnostics(int $parser): void
    {
        self::$parsers[$parser]['errorCode'] = 0;
        // Match Expat fresh-parser defaults (line/column start at 1; #25286).
        self::$parsers[$parser]['line'] = 1;
        self::$parsers[$parser]['column'] = 1;
        self::$parsers[$parser]['byteIndex'] = 0;
    }

    private static function recordSuccessfulParse(int $parser, string $data): void
    {
        self::$parsers[$parser]['errorCode'] = 0;
        // Expat: byte index is end-of-buffer; column is 1-based within the current line
        // (php-src ext/xml/xml.c XML_GetCurrentColumnNumber; #25817).
        self::setParserCursor($parser, $data, \strlen($data));
    }

    /**
     * @param array{level: int, code: int, column: int, message: string, file: string, line: int, byteIndex?: int} $error
     */
    private static function recordParserError(int $parser, array $error, string $data): void
    {
        self::$parsers[$parser]['errorCode'] = $error['code'];
        // Premature-end / unclosed-token: Expat leaves the cursor after the last complete
        // start-tag event (not always EOF), with a single-char `<X>` buffer quirk (#25817).
        if (5 === $error['code'] || self::XML_ERR_UNCLOSED_NODE_TAG === $error['code']) {
            self::recordExpatParseCursor($parser, $data, true);

            return;
        }
        $byteIndex = $error['byteIndex'] ?? \strlen($data);
        self::$parsers[$parser]['line'] = $error['line'];
        self::$parsers[$parser]['column'] = $error['column'];
        self::$parsers[$parser]['byteIndex'] = $byteIndex;
    }

    /**
     * Apply Expat post-parse line/column/byte diagnostics (php-src ext/xml/xml.c; #25817).
     *
     * @param bool $prematureEnd when the document still has open elements (err 5 / non-final)
     */
    private static function recordExpatParseCursor(int $parser, string $data, bool $prematureEnd): void
    {
        if ($prematureEnd) {
            self::setParserCursor($parser, $data, self::expatCursorByteIndexForPrematureEnd($data));

            return;
        }
        self::setParserCursor($parser, $data, \strlen($data));
    }

    /**
     * Expat byte index after an unclosed-element parse (XML_ERROR_UNCLOSED_TOKEN / #25817).
     *
     * Normally the end of the last complete start-tag (`>`). Quirk: when the entire buffer is
     * exactly one single-character bare start tag (`<r>`), Expat leaves byte index 0.
     */
    private static function expatCursorByteIndexForPrematureEnd(string $data): int
    {
        if (1 === preg_match('/^<[A-Za-z_]>$/', $data)) {
            return 0;
        }
        $afterStart = self::findLastCompleteStartTagEnd($data);

        return null !== $afterStart ? $afterStart : \strlen($data);
    }

    /** @return null|int byte offset immediately after the last complete start-tag `>` */
    private static function findLastCompleteStartTagEnd(string $data): ?int
    {
        $len = \strlen($data);
        $last = null;
        $scan = 0;
        while ($scan < $len) {
            if ('<' !== $data[$scan]) {
                ++$scan;

                continue;
            }
            if (preg_match('/\G<\/([A-Za-z_][\w:.-]*)>/s', $data, $close, 0, $scan)) {
                $scan += \strlen($close[0]);

                continue;
            }
            if (preg_match('/\G<([A-Za-z_][\w:.-]*)(\s[^>]*)?\/>/s', $data, $sc, 0, $scan)) {
                $scan += \strlen($sc[0]);
                $last = $scan;

                continue;
            }
            if (preg_match('/\G<([A-Za-z_][\w:.-]*)(\s[^>]*)?>/s', $data, $open, 0, $scan)) {
                $scan += \strlen($open[0]);
                $last = $scan;

                continue;
            }
            $cdata = self::parseCdataSectionAt($data, $scan);
            if (null !== $cdata) {
                $scan = $cdata['end'];

                continue;
            }
            $comment = self::parseCommentAt($data, $scan);
            if (null !== $comment) {
                $scan = $comment['end'];

                continue;
            }
            $pi = self::parseProcessingInstructionAt($data, $scan);
            if (null !== $pi) {
                $scan = $pi['end'];

                continue;
            }
            ++$scan;
        }

        return $last;
    }

    /** Set parser line/column/byteIndex from a 0-based end-relative byte index into $data. */
    private static function setParserCursor(int $parser, string $data, int $byteIndex): void
    {
        if ($byteIndex < 0) {
            $byteIndex = 0;
        }
        $len = \strlen($data);
        if ($byteIndex > $len) {
            $byteIndex = $len;
        }
        $prefix = substr($data, 0, $byteIndex);
        self::$parsers[$parser]['byteIndex'] = $byteIndex;
        self::$parsers[$parser]['line'] = 1 + substr_count($prefix, "\n");
        self::$parsers[$parser]['column'] = self::columnOnLine($data, $byteIndex);
    }

    public static function isWellFormed(string $data): bool
    {
        return null === self::validateWellFormed($data);
    }

    /**
     * xml_parse_into_struct() — build values/index arrays (#3494).
     *
     * @return array{status: int, values: HashTable, index: HashTable}
     */
    public static function parseIntoStruct(
        Context $ctx,
        int $parser,
        string $data,
        ?Frame $frame = null
    ): array {
        if (!isset(self::$parsers[$parser])) {
            throw new \ValueError('xml_parse_into_struct(): Argument #1 ($parser) must be a valid XML parser');
        }

        $error = self::validateWellFormed($data);
        if (null !== $error) {
            $error = self::expatAdjustLibxmlError($error);
            self::recordParserError($parser, $error, $data);
            \PHPCompiler\ext\libxml\VmLibxml::recordError($error);

            return [
                'status' => 0,
                'values' => new \PHPCompiler\VM\HashTable(),
                'index' => new \PHPCompiler\VM\HashTable(),
            ];
        }

        self::recordSuccessfulParse($parser, $data);
        $state = self::$parsers[$parser];
        $built = VmXmlStructBuilder::build($data, [
            'nsAware' => !empty($state['nsAware']),
            'nsSeparator' => (string) ($state['nsSeparator'] ?? ':'),
            'caseFolding' => 0 !== ($state['options'][XmlConstants::XML_OPTION_CASE_FOLDING] ?? 1),
        ]);
        $result = $built->result();

        return [
            'status' => 1,
            'values' => $result['values'],
            'index' => $result['index'],
        ];
    }

    /** @return null|int byte offset after one element starting at $pos */
    public static function findElementEndForStruct(string $content, int $pos): ?int
    {
        return self::findElementEnd($content, $pos);
    }

    /**
     * Validate XML well-formedness and record libxml errors when invalid (#14185).
     *
     * Also rejects undeclared general entities (XML_ERR_UNDECLARED_ENTITY) for SimpleXML /
     * load-string callers (#22775). DOMDocument::loadXML uses {@see validationErrorRecords}
     * then its own DTD-aware scan — keep entity checks out of that structural path (#22774).
     *
     * Reports every record from {@see validationErrorRecords} (e.g. tag mismatch 76 + premature
     * end 77) so SimpleXML matches DOM / libxml2 under libxml_use_internal_errors (#28658 / #25064).
     */
    public static function validateAndReport(Context $ctx, string $data, ?Frame $frame = null): bool
    {
        $records = self::validationErrorRecords($data);
        if ([] === $records) {
            $element = self::stripDocumentMiscEnvelope(trim($data));
            if ('' !== $element) {
                $entity = self::detectUndeclaredEntityRef($element);
                if (null !== $entity) {
                    $records = [$entity];
                }
            }
        }
        if ([] === $records) {
            return true;
        }

        foreach ($records as $error) {
            \PHPCompiler\ext\libxml\VmLibxml::handleError($ctx, $error, $frame);
        }

        return false;
    }

    /**
     * @return null|array{level: int, code: int, column: int, message: string, file: string, line: int}
     */
    public static function validationErrorRecord(string $data): ?array
    {
        $records = self::validationErrorRecords($data);

        return $records[0] ?? null;
    }

    /**
     * libxml may emit multiple FATAL errors for one malformed document (e.g. unclosed start tag
     * plus premature end in outer element — php-src ext/dom/document.c via libxml2; #18332 /
     * tag mismatch then premature end — #25064).
     *
     * @return list<array{level: int, code: int, column: int, message: string, file: string, line: int}>
     */
    public static function validationErrorRecords(string $data): array
    {
        $primary = self::validateWellFormed($data);
        if (null === $primary) {
            return [];
        }

        $records = [$primary];
        if (self::XML_ERR_TAG_NOT_FINISHED === $primary['code']) {
            // ltrim only — trailing newlines are significant for EOF line/column (#24319).
            $secondary = self::detectPrematureEnd(ltrim($data));
            if (null !== $secondary && self::XML_ERR_UNCLOSED_NODE_TAG === $secondary['code']) {
                $records[] = $secondary;
            }
        } elseif (self::XML_ERR_TAG_NAME_MISMATCH === $primary['code']) {
            // libxml2 recovers by popping the expected element, then may still leave ancestors
            // open → XML_ERR_UNCLOSED_NODE_TAG at the mismatch locus (#25064).
            $secondary = self::detectPrematureEndAfterMismatchRecovery(ltrim($data));
            if (null !== $secondary) {
                $records[] = $secondary;
            }
        }

        return $records;
    }

    /**
     * @return null|array{level: int, code: int, column: int, message: string, file: string, line: int}
     */
    private static function validateWellFormed(string $data): ?array
    {
        // Preserve trailing newlines for libxml EOF line/column on premature-end (#24319).
        $trimmed = ltrim($data);
        if ('' === $trimmed) {
            return self::errorRecord(1, 1, 'Document is empty', 4, LibxmlConstants::LIBXML_ERR_FATAL, 0);
        }
        if ('<' !== $trimmed[0]) {
            return self::errorRecord(1, 1, 'Start tag expected, \'<\' not found', 4, LibxmlConstants::LIBXML_ERR_FATAL, 0);
        }

        // XML 1.0 document ::= prolog element Misc* — comments/PIs may surround the root (#19361).
        $trimmed = self::stripDocumentMiscEnvelope($trimmed);
        if ('' === $trimmed) {
            return self::errorRecord(1, 1, 'Document is empty', 4, LibxmlConstants::LIBXML_ERR_FATAL, 0);
        }
        if ('<' !== $trimmed[0]) {
            return self::errorRecord(1, 1, 'Start tag expected, \'<\' not found', 4, LibxmlConstants::LIBXML_ERR_FATAL, 0);
        }

        // Bare '<' / '< ' / '<>' / '<9' — libxml XML_ERR_NAME_REQUIRED before generic code 4 (#22655).
        $nameRequired = self::detectInvalidStartTagName($trimmed);
        if (null !== $nameRequired) {
            return $nameRequired;
        }

        $unclosed = self::detectUnclosedStartTag($trimmed);
        if (null !== $unclosed) {
            return $unclosed;
        }

        if (preg_match('/^<([A-Za-z_][\w:.-]*)(\s[^>]*)?\/>\s*$/s', $trimmed)) {
            return null;
        }

        $mismatch = self::detectTagMismatch($trimmed, 0);
        if (null !== $mismatch) {
            return $mismatch;
        }

        if (!preg_match('/^<([A-Za-z_][\w:.-]*)(\s[^>]*)?>(.*)<\/\1>\s*$/s', $trimmed, $matches)) {
            $premature = self::detectPrematureEnd($trimmed);
            if (null !== $premature) {
                return $premature;
            }

            return self::errorRecord(1, 1, 'Malformed XML document', 4);
        }

        $error = self::validateFragment($matches[3]);
        if (null === $error) {
            return null;
        }

        return self::adjustFragmentErrorOffset($error, $trimmed, $matches[3]);
    }

    /**
     * Scan markup for undeclared / unterminated entity refs (libxml2 XML_ERR_UNDECLARED_ENTITY;
     * php-src ext/simplexml/sxe.c + ext/dom/document.c; #22775 / #22774).
     *
     * Skips CDATA, comments, and PIs. Predefined entities and numeric character references
     * are accepted. DTD general entities are not expanded here (caller must pass declared
     * names when DOCTYPE support is wired).
     *
     * @param array<string, string|true> $generalEntities
     *
     * @return null|array{level: int, code: int, column: int, message: string, file: string, line: int, byteIndex?: int}
     */
    public static function detectUndeclaredEntityRef(string $elementXml, array $generalEntities = []): ?array
    {
        $len = \strlen($elementXml);
        $pos = 0;
        while ($pos < $len) {
            if ('<' === $elementXml[$pos]) {
                $cdata = self::parseCdataSectionAt($elementXml, $pos);
                if (null !== $cdata) {
                    $pos = $cdata['end'];

                    continue;
                }
                $comment = self::parseCommentAt($elementXml, $pos);
                if (null !== $comment) {
                    $pos = $comment['end'];

                    continue;
                }
                $pi = self::parseProcessingInstructionAt($elementXml, $pos);
                if (null !== $pi) {
                    $pos = $pi['end'];

                    continue;
                }
                ++$pos;

                continue;
            }
            if ('&' !== $elementXml[$pos]) {
                ++$pos;

                continue;
            }
            $amp = $pos;
            $afterAmp = $amp + 1;
            if ($afterAmp >= $len) {
                return self::errorRecord(
                    1,
                    $amp + 1,
                    "EntityRef: expecting ';'\n",
                    self::XML_ERR_ENTITYREF_SEMICOL_MISSING,
                    LibxmlConstants::LIBXML_ERR_FATAL,
                    $amp
                );
            }
            // Character references: &#...; / &#x...;
            if ('#' === $elementXml[$afterAmp]) {
                $semi = strpos($elementXml, ';', $afterAmp + 1);
                if (false === $semi) {
                    return self::errorRecord(
                        1,
                        $len,
                        "EntityRef: expecting ';'\n",
                        self::XML_ERR_ENTITYREF_SEMICOL_MISSING,
                        LibxmlConstants::LIBXML_ERR_FATAL,
                        $len - 1
                    );
                }
                $pos = $semi + 1;

                continue;
            }
            $semi = strpos($elementXml, ';', $afterAmp);
            if (false === $semi) {
                $nameEnd = $afterAmp;
                while ($nameEnd < $len && 1 === preg_match('/[A-Za-z0-9._:-]/', $elementXml[$nameEnd])) {
                    ++$nameEnd;
                }

                return self::errorRecord(
                    1,
                    $nameEnd + 1,
                    "EntityRef: expecting ';'\n",
                    self::XML_ERR_ENTITYREF_SEMICOL_MISSING,
                    LibxmlConstants::LIBXML_ERR_FATAL,
                    $nameEnd
                );
            }
            $nameEnd = $afterAmp;
            while ($nameEnd < $semi && 1 === preg_match('/[A-Za-z0-9._:-]/', $elementXml[$nameEnd])) {
                ++$nameEnd;
            }
            if ($nameEnd !== $semi) {
                return self::errorRecord(
                    1,
                    $nameEnd + 1,
                    "EntityRef: expecting ';'\n",
                    self::XML_ERR_ENTITYREF_SEMICOL_MISSING,
                    LibxmlConstants::LIBXML_ERR_FATAL,
                    $nameEnd
                );
            }
            $refName = substr($elementXml, $afterAmp, $semi - $afterAmp);
            if ('' === $refName) {
                return self::errorRecord(
                    1,
                    $semi + 1,
                    "EntityRef: expecting ';'\n",
                    self::XML_ERR_ENTITYREF_SEMICOL_MISSING,
                    LibxmlConstants::LIBXML_ERR_FATAL,
                    $semi
                );
            }
            if (isset($generalEntities[$refName]) || null !== self::decodePredefinedXmlEntityName($refName)) {
                $pos = $semi + 1;

                continue;
            }

            // XML_ERR_UNDECLARED_ENTITY — column is 1-based index of the char after ';'.
            return self::errorRecord(
                1,
                $semi + 2,
                "Entity '".$refName."' not defined\n",
                self::XML_ERR_UNDECLARED_ENTITY,
                LibxmlConstants::LIBXML_ERR_FATAL,
                $semi + 1
            );
        }

        return null;
    }

    private static function decodePredefinedXmlEntityName(string $name): ?string
    {
        return match ($name) {
            'amp' => '&',
            'lt' => '<',
            'gt' => '>',
            'quot' => '"',
            'apos' => "'",
            default => null,
        };
    }

    /**
     * Strip leading/trailing Misc (Comment | PI | S) and an optional XML declaration / DOCTYPE
     * so the remaining string is the document element (php-src libxml document production; #19361).
     */
    public static function stripDocumentMiscEnvelope(string $xml): string
    {
        $pos = 0;
        $len = \strlen($xml);
        $pos = self::skipXmlWhitespace($xml, $pos);
        if (preg_match('/\G<\?xml\s[^?]*\?>/is', $xml, $decl, 0, $pos)) {
            $pos += \strlen($decl[0]);
            $pos = self::skipXmlWhitespace($xml, $pos);
        }
        while ($pos < $len) {
            $miscEnd = self::consumeDocumentMiscAt($xml, $pos);
            if (null === $miscEnd) {
                break;
            }
            $pos = self::skipXmlWhitespace($xml, $miscEnd);
        }
        if (preg_match('/\G<!DOCTYPE\s/i', $xml, $doctypeOpen, 0, $pos)) {
            unset($doctypeOpen);
            $doctypeEnd = self::findDoctypeEnd($xml, $pos);
            if (null !== $doctypeEnd) {
                $pos = self::skipXmlWhitespace($xml, $doctypeEnd);
            }
        }
        while ($pos < $len) {
            $miscEnd = self::consumeDocumentMiscAt($xml, $pos);
            if (null === $miscEnd) {
                break;
            }
            $pos = self::skipXmlWhitespace($xml, $miscEnd);
        }
        $rootStart = $pos;
        if ($rootStart >= $len || '<' !== $xml[$rootStart]) {
            return substr($xml, $rootStart);
        }
        $rootEnd = self::findElementEnd($xml, $rootStart);
        if (null === $rootEnd) {
            return substr($xml, $rootStart);
        }

        return substr($xml, $rootStart, $rootEnd - $rootStart);
    }

    /**
     * Drop XML declaration + DOCTYPE only — keep Comment/PI Misc for SAX default/PI handlers (#20333).
     *
     * Unlike {@see stripDocumentMiscEnvelope}, trailing Misc after the root is preserved.
     */
    public static function stripXmlDeclAndDoctypeKeepMisc(string $xml): string
    {
        $pos = 0;
        $len = \strlen($xml);
        $pos = self::skipXmlWhitespace($xml, $pos);
        if (preg_match('/\G<\?xml\s[^?]*\?>/is', $xml, $decl, 0, $pos)) {
            $pos += \strlen($decl[0]);
            $pos = self::skipXmlWhitespace($xml, $pos);
        }
        // Comments/PIs before DOCTYPE stay in the returned string (Zend default handler sees them).
        $beforeDoctype = $pos;
        $scan = $pos;
        while ($scan < $len) {
            $ws = self::skipXmlWhitespace($xml, $scan);
            if (preg_match('/\G<!DOCTYPE\s/i', $xml, $doctypeOpen, 0, $ws)) {
                unset($doctypeOpen);
                $doctypeEnd = self::findDoctypeEnd($xml, $ws);
                if (null === $doctypeEnd) {
                    break;
                }
                // Drop decl/doctype region but keep any Misc that appeared before DOCTYPE.
                $prefix = substr($xml, $beforeDoctype, $ws - $beforeDoctype);
                $suffix = substr($xml, self::skipXmlWhitespace($xml, $doctypeEnd));

                return $prefix.$suffix;
            }
            $miscEnd = self::consumeDocumentMiscAt($xml, $ws);
            if (null === $miscEnd) {
                break;
            }
            $scan = $miscEnd;
        }

        return substr($xml, $beforeDoctype);
    }

    private static function skipXmlWhitespace(string $xml, int $pos): int
    {
        $len = \strlen($xml);
        while ($pos < $len && 1 === preg_match('/\s/', $xml[$pos])) {
            ++$pos;
        }

        return $pos;
    }

    /** @return null|int byte offset after one Comment or PI at $pos (not <?xml …?>) */
    public static function consumeDocumentMiscAt(string $content, int $pos): ?int
    {
        $comment = self::parseCommentAt($content, $pos);
        if (null !== $comment) {
            return $comment['end'];
        }
        $pi = self::parseProcessingInstructionAt($content, $pos);
        if (null !== $pi) {
            return $pi['end'];
        }

        return null;
    }

    /**
     * Parse a processing instruction at $pos (excludes the XML declaration; #19361).
     *
     * @return null|array{end: int, target: string, data: string}
     */
    public static function parseProcessingInstructionAt(string $content, int $pos): ?array
    {
        if (!isset($content[$pos]) || '<' !== $content[$pos]) {
            return null;
        }
        if (!preg_match('/\G<\?([A-Za-z_][\w:.-]*)(?:\s+([\s\S]*?))?\?>/s', $content, $match, 0, $pos)) {
            return null;
        }
        if (0 === strcasecmp($match[1], 'xml')) {
            return null;
        }

        return [
            'end' => $pos + \strlen($match[0]),
            'target' => $match[1],
            'data' => isset($match[2]) ? trim($match[2]) : '',
        ];
    }

    /** @return null|int byte offset after <!DOCTYPE …> starting at $pos */
    private static function findDoctypeEnd(string $xml, int $pos): ?int
    {
        if (!preg_match('/\G<!DOCTYPE\s/i', $xml, $doctypeOpen, 0, $pos)) {
            return null;
        }
        unset($doctypeOpen);
        $len = \strlen($xml);
        $i = $pos + 9;
        $bracketDepth = 0;
        while ($i < $len) {
            $ch = $xml[$i];
            if ('[' === $ch) {
                ++$bracketDepth;
            } elseif (']' === $ch && $bracketDepth > 0) {
                --$bracketDepth;
            } elseif ('>' === $ch && 0 === $bracketDepth) {
                return $i + 1;
            }
            ++$i;
        }

        return null;
    }

    /**
     * @param array{level: int, code: int, column: int, message: string, file: string, line: int, byteIndex?: int} $error
     *
     * @return array{level: int, code: int, column: int, message: string, file: string, line: int, byteIndex?: int}
     */
    private static function adjustFragmentErrorOffset(array $error, string $document, string $fragment): array
    {
        if (!isset($error['byteIndex'])) {
            return $error;
        }
        $offset = strpos($document, $fragment);
        if (false === $offset) {
            return $error;
        }
        $error['byteIndex'] += $offset;
        $error['column'] = $error['byteIndex'] + 1;

        return $error;
    }

    /**
     * Match libxml "StartTag: invalid element name" (XML_ERR_NAME_REQUIRED / 68; #22655).
     *
     * Fired when a start-tag opener has no NameStartChar (EOF, whitespace, digit, `>`, `-`, `.`, …).
     * Leaves `</`, `<?`, `<!`, `<:`, and letter/`_`/non-ASCII names to sibling diagnostics.
     *
     * @return null|array{level: int, code: int, column: int, message: string, file: string, line: int}
     */
    private static function detectInvalidStartTagName(string $data): ?array
    {
        $len = \strlen($data);
        if ($len < 1 || '<' !== $data[0]) {
            return null;
        }
        if ($len >= 2) {
            $next = $data[1];
            if ('/' === $next || '?' === $next || '!' === $next || ':' === $next) {
                return null;
            }
            // ASCII NameStartChar or non-ASCII (libxml accepts some Unicode names) → not this error.
            if (preg_match('/^[A-Za-z_]/', $next) || \ord($next) >= 0x80) {
                return null;
            }
        }

        // Column 2: libxml points at the missing/invalid name byte after '<' (#22655).
        return self::errorRecord(
            1,
            2,
            'StartTag: invalid element name',
            self::XML_ERR_NAME_REQUIRED,
            LibxmlConstants::LIBXML_ERR_FATAL
        );
    }

    /**
     * Match libxml "Premature end of data in tag …" (XML_ERR_UNCLOSED_NODE_TAG; #14467 / #24319).
     *
     * libxml2 reports the **innermost** still-open element (top of the element stack) at EOF,
     * embeds that tag's open line in the message, and places LibXMLError line/column at EOF.
     *
     * @return null|array{level: int, code: int, column: int, message: string, file: string, line: int}
     */
    private static function detectPrematureEnd(string $data): ?array
    {
        $len = \strlen($data);
        if ($len < 1 || '<' !== $data[0]) {
            return null;
        }

        /** @var list<array{name: string, openLine: int}> $stack */
        $stack = [];
        $scan = 0;
        while ($scan < $len) {
            if ('<' !== $data[$scan]) {
                ++$scan;

                continue;
            }
            if (preg_match('/\G<\/([A-Za-z_][\w:.-]*)>/s', $data, $close, 0, $scan)) {
                $name = $close[1];
                if ([] === $stack || end($stack)['name'] !== $name) {
                    // Mismatch is handled elsewhere; do not invent a premature-end here.
                    return null;
                }
                array_pop($stack);
                $scan += \strlen($close[0]);

                continue;
            }
            if (preg_match('/\G<([A-Za-z_][\w:.-]*)(\s[^>]*)?\/>/s', $data, $sc, 0, $scan)) {
                $scan += \strlen($sc[0]);

                continue;
            }
            if (preg_match('/\G<([A-Za-z_][\w:.-]*)(\s[^>]*)?>/s', $data, $open, 0, $scan)) {
                $openLine = 1 + substr_count(substr($data, 0, $scan), "\n");
                $stack[] = ['name' => $open[1], 'openLine' => $openLine];
                $scan += \strlen($open[0]);

                continue;
            }
            // Incomplete start tag (no '>') — leave stack as-is (outer properly-opened tags).
            if (preg_match('/\G<([A-Za-z_][\w:.-]*)/s', $data, $partial, 0, $scan)) {
                break;
            }
            $cdata = self::parseCdataSectionAt($data, $scan);
            if (null !== $cdata) {
                $scan = $cdata['end'];

                continue;
            }
            $comment = self::parseCommentAt($data, $scan);
            if (null !== $comment) {
                $scan = $comment['end'];

                continue;
            }
            $pi = self::parseProcessingInstructionAt($data, $scan);
            if (null !== $pi) {
                $scan = $pi['end'];

                continue;
            }
            ++$scan;
        }

        if ([] === $stack) {
            return null;
        }

        $top = $stack[\count($stack) - 1];
        $tag = $top['name'];
        $openLine = $top['openLine'];
        // EOF position: after last byte; a trailing newline advances to column 1 of the next line.
        if ($len > 0 && "\n" === $data[$len - 1]) {
            $eofLine = 1 + substr_count($data, "\n");
            $eofColumn = 1;
        } else {
            $lastNl = strrpos($data, "\n");
            $eofLine = 1 + substr_count($data, "\n");
            $eofColumn = false === $lastNl ? $len + 1 : $len - $lastNl;
        }

        return self::errorRecord(
            $eofLine,
            $eofColumn,
            "Premature end of data in tag {$tag} line {$openLine}\n",
            self::XML_ERR_UNCLOSED_NODE_TAG,
            LibxmlConstants::LIBXML_ERR_FATAL
        );
    }

    /**
     * Match libxml fatal "Couldn't find end of Start Tag …" (php-src xmlerror.c; #14396).
     *
     * @return null|array{level: int, code: int, column: int, message: string, file: string, line: int}
     */
    private static function detectUnclosedStartTag(string $data): ?array
    {
        $len = \strlen($data);
        for ($pos = 0; $pos < $len; ++$pos) {
            if ('<' !== $data[$pos]) {
                continue;
            }
            if ($pos + 1 < $len && '/' === $data[$pos + 1]) {
                continue;
            }
            if ($pos + 1 < $len && '?' === $data[$pos + 1]) {
                $end = strpos($data, '?>', $pos + 2);
                $pos = false === $end ? $len : $end + 1;

                continue;
            }
            if ($pos + 1 < $len && '!' === $data[$pos + 1]) {
                if (str_starts_with(substr($data, $pos), '<!--')) {
                    $end = strpos($data, '-->', $pos + 4);
                    $pos = false === $end ? $len : $end + 2;

                    continue;
                }
                if (str_starts_with(substr($data, $pos), '<![CDATA[')) {
                    $end = strpos($data, ']]>', $pos + 9);
                    $pos = false === $end ? $len : $end + 2;

                    continue;
                }
            }
            if (!preg_match('/\G<([A-Za-z_][\w:.-]*)(\s[^>]*)?/s', $data, $open, 0, $pos)) {
                continue;
            }
            $tag = $open[1];
            $after = $pos + \strlen($open[0]);
            if ($after < $len && '/' === $data[$after]) {
                if ($after + 1 >= $len || '>' !== $data[$after + 1]) {
                    return self::unclosedStartTagError($data, $pos, $tag);
                }

                continue;
            }
            if ($after >= $len || '>' !== $data[$after]) {
                return self::unclosedStartTagError($data, $pos, $tag);
            }
        }

        return null;
    }

    /**
     * @return array{level: int, code: int, column: int, message: string, file: string, line: int}
     */
    private static function unclosedStartTagError(string $data, int $tagPos, string $tag): array
    {
        $line = 1 + substr_count(substr($data, 0, $tagPos), "\n");
        $message = \sprintf("Couldn't find end of Start Tag %s line %d", $tag, $line);
        // libxml points at EOF (1-based past last byte), not the '<' of the open tag (#24319 / #18332).
        $column = \strlen($data) + 1;

        return self::errorRecord(
            $line,
            $column,
            $message,
            self::XML_ERR_TAG_NOT_FINISHED,
            LibxmlConstants::LIBXML_ERR_FATAL
        );
    }

    /**
     * Parse a CDATA section at $pos (php-src libxml CDATA; #17526).
     *
     * @return null|array{end: int, data: string}
     */
    public static function parseCdataSectionAt(string $content, int $pos): ?array
    {
        if (!isset($content[$pos]) || '<' !== $content[$pos]) {
            return null;
        }
        if (!str_starts_with(substr($content, $pos), '<![CDATA[')) {
            return null;
        }
        $dataStart = $pos + 9;
        $endMarker = strpos($content, ']]>', $dataStart);
        if (false === $endMarker) {
            return null;
        }

        return [
            'end' => $endMarker + 3,
            'data' => substr($content, $dataStart, $endMarker - $dataStart),
        ];
    }

    /**
     * Parse an XML comment at $pos (php-src libxml comment nodes; #17530).
     *
     * @return null|array{end: int, data: string}
     */
    public static function parseCommentAt(string $content, int $pos): ?array
    {
        if (!isset($content[$pos]) || '<' !== $content[$pos]) {
            return null;
        }
        if (!str_starts_with(substr($content, $pos), '<!--')) {
            return null;
        }
        $dataStart = $pos + 4;
        $endMarker = strpos($content, '-->', $dataStart);
        if (false === $endMarker) {
            return null;
        }

        return [
            'end' => $endMarker + 3,
            'data' => substr($content, $dataStart, $endMarker - $dataStart),
        ];
    }

    /**
     * Validate sibling content inside an element (text nodes and child elements).
     *
     * @return null|array{level: int, code: int, column: int, message: string, file: string, line: int}
     */
    private static function validateFragment(string $content): ?array
    {
        $pos = 0;
        $len = \strlen($content);
        while ($pos < $len) {
            if (preg_match('/\G\s+/s', $content, $m, 0, $pos)) {
                $pos += \strlen($m[0]);

                continue;
            }
            if ($pos >= $len) {
                return null;
            }
            if ('<' !== $content[$pos]) {
                $next = strpos($content, '<', $pos);
                $pos = (false === $next) ? $len : $next;

                continue;
            }
            $cdata = self::parseCdataSectionAt($content, $pos);
            if (null !== $cdata) {
                $pos = $cdata['end'];

                continue;
            }
            $comment = self::parseCommentAt($content, $pos);
            if (null !== $comment) {
                $pos = $comment['end'];

                continue;
            }
            $pi = self::parseProcessingInstructionAt($content, $pos);
            if (null !== $pi) {
                $pos = $pi['end'];

                continue;
            }
            $end = self::findElementEnd($content, $pos);
            if (null === $end) {
                $mismatch = self::detectTagMismatch($content, $pos);
                if (null !== $mismatch) {
                    return $mismatch;
                }
                $unclosed = self::detectUnclosedStartTag(substr($content, $pos));
                if (null !== $unclosed) {
                    return $unclosed;
                }

                return self::errorRecord(1, $pos + 1, 'Malformed XML document', 4, LibxmlConstants::LIBXML_ERR_FATAL, $pos);
            }
            $element = substr($content, $pos, $end - $pos);
            $error = self::validateWellFormed($element);
            if (null !== $error) {
                return $error;
            }
            $pos = $end;
        }

        return null;
    }

    /**
     * Detect mismatched end tags (libxml XML_ERR_TAG_NAME_MISMATCH; #18120 / #25064).
     *
     * Message embeds the **open line** of the expected element (1-based, libxml2). Expat's
     * xml_parse() libxml bridge rewrites that to "line 0" via {@see expatAdjustLibxmlError}.
     *
     * @return null|array{level: int, code: int, column: int, message: string, file: string, line: int, byteIndex: int}
     */
    private static function detectTagMismatch(string $content, int $pos): ?array
    {
        if (!preg_match('/\G<([A-Za-z_][\w:.-]*)(\s[^>]*)?>/s', $content, $open, 0, $pos)) {
            return null;
        }

        /** @var list<array{name: string, openLine: int}> $stack */
        $stack = [['name' => $open[1], 'openLine' => 1 + substr_count(substr($content, 0, $pos), "\n")]];
        $scan = $pos + \strlen($open[0]);
        $len = \strlen($content);
        while ($scan < $len && [] !== $stack) {
            if (preg_match('/\G<\/([A-Za-z_][\w:.-]*)>/s', $content, $close, 0, $scan)) {
                $name = $close[1];
                $top = $stack[\count($stack) - 1];
                if ($top['name'] !== $name) {
                    $line = 1 + substr_count(substr($content, 0, $scan), "\n");
                    $byteIndex = $scan + \strlen($close[0]);
                    $message = \sprintf(
                        "Opening and ending tag mismatch: %s line %d and %s\n",
                        $top['name'],
                        $top['openLine'],
                        $name
                    );

                    return self::errorRecord(
                        $line,
                        self::columnOnLine($content, $byteIndex),
                        $message,
                        self::XML_ERR_TAG_NAME_MISMATCH,
                        LibxmlConstants::LIBXML_ERR_FATAL,
                        $byteIndex
                    );
                }
                array_pop($stack);
                $scan += \strlen($close[0]);
                if ([] === $stack) {
                    return null;
                }

                continue;
            }
            if (preg_match('/\G<([A-Za-z_][\w:.-]*)(\s[^>]*)?\/>/s', $content, $sc, 0, $scan)) {
                $scan += \strlen($sc[0]);

                continue;
            }
            if (preg_match('/\G<([A-Za-z_][\w:.-]*)(\s[^>]*)?>/s', $content, $nested, 0, $scan)) {
                $openLine = 1 + substr_count(substr($content, 0, $scan), "\n");
                $stack[] = ['name' => $nested[1], 'openLine' => $openLine];
                $scan += \strlen($nested[0]);

                continue;
            }
            $cdata = self::parseCdataSectionAt($content, $scan);
            if (null !== $cdata) {
                $scan = $cdata['end'];

                continue;
            }
            $comment = self::parseCommentAt($content, $scan);
            if (null !== $comment) {
                $scan = $comment['end'];

                continue;
            }
            $pi = self::parseProcessingInstructionAt($content, $scan);
            if (null !== $pi) {
                $scan = $pi['end'];

                continue;
            }
            ++$scan;
        }

        return null;
    }

    /**
     * After XML_ERR_TAG_NAME_MISMATCH, libxml2 pops the expected element and continues; if the
     * stack is still non-empty at EOF, it emits XML_ERR_UNCLOSED_NODE_TAG at the last mismatch
     * locus (php-src / libxml2; #25064).
     *
     * @return null|array{level: int, code: int, column: int, message: string, file: string, line: int}
     */
    private static function detectPrematureEndAfterMismatchRecovery(string $data): ?array
    {
        $len = \strlen($data);
        if ($len < 1 || '<' !== $data[0]) {
            return null;
        }

        /** @var list<array{name: string, openLine: int}> $stack */
        $stack = [];
        $scan = 0;
        $lastMismatchLine = null;
        $lastMismatchColumn = null;
        while ($scan < $len) {
            if ('<' !== $data[$scan]) {
                ++$scan;

                continue;
            }
            if (preg_match('/\G<\/([A-Za-z_][\w:.-]*)>/s', $data, $close, 0, $scan)) {
                $name = $close[1];
                $closeEnd = $scan + \strlen($close[0]);
                if ([] === $stack || end($stack)['name'] !== $name) {
                    // Recovery: pop expected top (if any), consume the close token, keep going.
                    if ([] !== $stack) {
                        array_pop($stack);
                    }
                    $lastMismatchLine = 1 + substr_count(substr($data, 0, $scan), "\n");
                    $lastMismatchColumn = self::columnOnLine($data, $closeEnd);
                    $scan = $closeEnd;

                    continue;
                }
                array_pop($stack);
                $scan = $closeEnd;

                continue;
            }
            if (preg_match('/\G<([A-Za-z_][\w:.-]*)(\s[^>]*)?\/>/s', $data, $sc, 0, $scan)) {
                $scan += \strlen($sc[0]);

                continue;
            }
            if (preg_match('/\G<([A-Za-z_][\w:.-]*)(\s[^>]*)?>/s', $data, $open, 0, $scan)) {
                $openLine = 1 + substr_count(substr($data, 0, $scan), "\n");
                $stack[] = ['name' => $open[1], 'openLine' => $openLine];
                $scan += \strlen($open[0]);

                continue;
            }
            if (preg_match('/\G<([A-Za-z_][\w:.-]*)/s', $data, $partial, 0, $scan)) {
                break;
            }
            $cdata = self::parseCdataSectionAt($data, $scan);
            if (null !== $cdata) {
                $scan = $cdata['end'];

                continue;
            }
            $comment = self::parseCommentAt($data, $scan);
            if (null !== $comment) {
                $scan = $comment['end'];

                continue;
            }
            $pi = self::parseProcessingInstructionAt($data, $scan);
            if (null !== $pi) {
                $scan = $pi['end'];

                continue;
            }
            ++$scan;
        }

        if ([] === $stack) {
            return null;
        }

        $top = $stack[\count($stack) - 1];
        if (null !== $lastMismatchLine && null !== $lastMismatchColumn) {
            $line = $lastMismatchLine;
            $column = $lastMismatchColumn;
        } elseif ($len > 0 && "\n" === $data[$len - 1]) {
            $line = 1 + substr_count($data, "\n");
            $column = 1;
        } else {
            $lastNl = strrpos($data, "\n");
            $line = 1 + substr_count($data, "\n");
            $column = false === $lastNl ? $len + 1 : $len - $lastNl;
        }

        return self::errorRecord(
            $line,
            $column,
            "Premature end of data in tag {$top['name']} line {$top['openLine']}\n",
            self::XML_ERR_UNCLOSED_NODE_TAG,
            LibxmlConstants::LIBXML_ERR_FATAL
        );
    }

    /**
     * 1-based column within the current line for a byte index past the last consumed byte
     * (libxml2 xmlerror.c; #25064).
     */
    private static function columnOnLine(string $content, int $byteIndex): int
    {
        if ($byteIndex <= 0) {
            return 1;
        }
        $prefix = substr($content, 0, $byteIndex);
        $lastNl = strrpos($prefix, "\n");

        return false === $lastNl ? $byteIndex + 1 : $byteIndex - $lastNl;
    }

    /**
     * Expat's libxml bridge embeds "line 0" in tag-mismatch detail (php-src ext/xml/xml.c; #18138).
     * DOM/libxml2 uses the element's open line — keep that in {@see detectTagMismatch}, rewrite here.
     *
     * @param array{level: int, code: int, column: int, message: string, file: string, line: int, byteIndex?: int} $error
     *
     * @return array{level: int, code: int, column: int, message: string, file: string, line: int, byteIndex?: int}
     */
    private static function expatAdjustLibxmlError(array $error): array
    {
        // libxml XML_ERR_UNCLOSED_NODE_TAG → Expat XML_ERROR_UNCLOSED_TOKEN (5)
        // "Invalid document end" (php-src ext/xml/xml.c; #25817).
        if (self::XML_ERR_UNCLOSED_NODE_TAG === $error['code']) {
            $error['code'] = 5;

            return $error;
        }
        if (self::XML_ERR_TAG_NAME_MISMATCH !== $error['code']) {
            return $error;
        }
        $error['message'] = (string) preg_replace(
            '/^(Opening and ending tag mismatch: \S+ line )\d+( and \S+)/',
            '${1}0${2}',
            $error['message']
        );

        return $error;
    }

    /** @return null|int byte offset after one element starting at $pos */
    private static function findElementEnd(string $content, int $pos): ?int
    {
        if (!isset($content[$pos]) || '<' !== $content[$pos]) {
            return null;
        }
        if (preg_match('/\G<([A-Za-z_][\w:.-]*)(\s[^>]*)?\/>/s', $content, $selfClose, 0, $pos)) {
            return $pos + \strlen($selfClose[0]);
        }
        if (!preg_match('/\G<([A-Za-z_][\w:.-]*)(\s[^>]*)?>/s', $content, $open, 0, $pos)) {
            return null;
        }

        /** @var list<string> $stack */
        $stack = [$open[1]];
        $scan = $pos + \strlen($open[0]);
        $len = \strlen($content);
        while ($scan < $len && [] !== $stack) {
            if (preg_match('/\G<\/([A-Za-z_][\w:.-]*)>/s', $content, $close, 0, $scan)) {
                $name = $close[1];
                if ([] === $stack || end($stack) !== $name) {
                    return null;
                }
                array_pop($stack);
                $scan += \strlen($close[0]);
                if ([] === $stack) {
                    return $scan;
                }

                continue;
            }
            if (preg_match('/\G<([A-Za-z_][\w:.-]*)(\s[^>]*)?\/>/s', $content, $sc, 0, $scan)) {
                $scan += \strlen($sc[0]);

                continue;
            }
            if (preg_match('/\G<([A-Za-z_][\w:.-]*)(\s[^>]*)?>/s', $content, $nested, 0, $scan)) {
                $stack[] = $nested[1];
                $scan += \strlen($nested[0]);

                continue;
            }
            $cdata = self::parseCdataSectionAt($content, $scan);
            if (null !== $cdata) {
                $scan = $cdata['end'];

                continue;
            }
            $comment = self::parseCommentAt($content, $scan);
            if (null !== $comment) {
                $scan = $comment['end'];

                continue;
            }
            $pi = self::parseProcessingInstructionAt($content, $scan);
            if (null !== $pi) {
                $scan = $pi['end'];

                continue;
            }
            ++$scan;
        }

        return null;
    }

    /**
     * @return array{level: int, code: int, column: int, message: string, file: string, line: int, byteIndex?: int}
     */
    private static function errorRecord(
        int $line,
        int $column,
        string $message,
        int $code,
        int $level = LibxmlConstants::LIBXML_ERR_FATAL,
        ?int $byteIndex = null
    ): array {
        $record = [
            'level' => $level,
            'code' => $code,
            'column' => $column,
            'message' => $message,
            'file' => '',
            'line' => $line,
        ];
        if (null !== $byteIndex) {
            $record['byteIndex'] = $byteIndex;
        }

        return $record;
    }
}
