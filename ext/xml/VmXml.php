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

        if (!$isFinal) {
            return 1;
        }

        $error = self::validateWellFormed($data);
        if (null === $error) {
            self::recordSuccessfulParse($parser, $data);
            if (null !== $parserObject) {
                VmXmlSaxDispatcher::dispatch($ctx, $parserObject, $data, $frame);
            }

            return 1;
        }

        self::recordParserError($parser, $error, $data);
        \PHPCompiler\ext\libxml\VmLibxml::recordError($error);

        return 0;
    }

    private static function clearParserDiagnostics(int $parser): void
    {
        self::$parsers[$parser]['errorCode'] = 0;
        self::$parsers[$parser]['line'] = 0;
        self::$parsers[$parser]['column'] = 0;
        self::$parsers[$parser]['byteIndex'] = 0;
    }

    private static function recordSuccessfulParse(int $parser, string $data): void
    {
        $byteIndex = \strlen($data);
        self::$parsers[$parser]['errorCode'] = 0;
        self::$parsers[$parser]['line'] = 1 + substr_count($data, "\n");
        self::$parsers[$parser]['byteIndex'] = $byteIndex;
        self::$parsers[$parser]['column'] = $byteIndex + 1;
    }

    /**
     * @param array{level: int, code: int, column: int, message: string, file: string, line: int, byteIndex?: int} $error
     */
    private static function recordParserError(int $parser, array $error, string $data): void
    {
        $byteIndex = $error['byteIndex'] ?? \strlen($data);
        self::$parsers[$parser]['errorCode'] = $error['code'];
        self::$parsers[$parser]['line'] = $error['line'];
        self::$parsers[$parser]['column'] = $error['column'];
        self::$parsers[$parser]['byteIndex'] = $byteIndex;
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
     */
    public static function validateAndReport(Context $ctx, string $data, ?Frame $frame = null): bool
    {
        $error = self::validationErrorRecord($data);
        if (null === $error) {
            return true;
        }

        \PHPCompiler\ext\libxml\VmLibxml::handleError($ctx, $error, $frame);

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
     * plus premature end in outer element — php-src ext/dom/document.c via libxml2; #18332).
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
            $secondary = self::detectPrematureEnd(trim($data));
            if (null !== $secondary && self::XML_ERR_UNCLOSED_NODE_TAG === $secondary['code']) {
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
        $trimmed = trim($data);
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

        if (preg_match('/^<([A-Za-z_][\w:.-]*)(\s[^>]*)?\/>$/s', $trimmed)) {
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
     * Match libxml "Premature end of data in tag …" (XML_ERR_UNCLOSED_NODE_TAG; #14467).
     *
     * @return null|array{level: int, code: int, column: int, message: string, file: string, line: int}
     */
    private static function detectPrematureEnd(string $data): ?array
    {
        if (!preg_match('/^<([A-Za-z_][\w:.-]*)(\s[^>]*)?>/s', $data, $open)) {
            return null;
        }
        $tag = $open[1];
        if (preg_match('/<\/'.preg_quote($tag, '/').'>\s*$/s', $data)) {
            return null;
        }
        $line = 1;

        return self::errorRecord(
            $line,
            1,
            "Premature end of data in tag {$tag} line {$line}",
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

        return self::errorRecord(
            $line,
            $tagPos + 1,
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
     * Detect mismatched end tags (libxml XML_ERR_TAG_NAME_MISMATCH / expat code 76; #18120).
     *
     * @return null|array{level: int, code: int, column: int, message: string, file: string, line: int, byteIndex: int}
     */
    private static function detectTagMismatch(string $content, int $pos): ?array
    {
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
                    $line = 1 + substr_count(substr($content, 0, $scan), "\n");
                    $byteIndex = $scan + \strlen($close[0]);
                    $expected = [] !== $stack ? (string) end($stack) : '';
                    $expatLine = $line - 1;
                    $message = \sprintf(
                        "Opening and ending tag mismatch: %s line %d and %s\n",
                        $expected,
                        $expatLine,
                        $name
                    );

                    return self::errorRecord(
                        $line,
                        $byteIndex + 1,
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
