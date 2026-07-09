<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xml;

use PHPCompiler\ext\libxml\LibxmlConstants;
use PHPCompiler\Frame;
use PHPCompiler\VM\Context;

/**
 * Minimal expat-shaped parser for xml_parse() v1 (#3494, #6058).
 *
 * Well-formedness probe records libxml errors via {@see \PHPCompiler\ext\libxml\VmLibxml}.
 */
final class VmXml
{
    /** libxml/xmlerror.h — XML_ERR_TAG_NOT_FINISHED (php-src ext/libxml/libxml.c). */
    private const XML_ERR_TAG_NOT_FINISHED = 73;

    /** libxml/xmlerror.h — XML_ERR_UNCLOSED_NODE_TAG (php-src ext/libxml/libxml.c; #14467). */
    private const XML_ERR_UNCLOSED_NODE_TAG = 77;

    /** @var array<int, array{errorCode: int}> */
    private static array $parsers = [];

    private static int $nextParserId = 0;

    public static function parserCreate(): int
    {
        $id = ++self::$nextParserId;
        self::$parsers[$id] = ['errorCode' => 0];

        return $id;
    }

    public static function parserFree(int $parser): bool
    {
        if (!isset(self::$parsers[$parser])) {
            return false;
        }
        unset(self::$parsers[$parser]);

        return true;
    }

    public static function getErrorCode(int $parser): int
    {
        if (!isset(self::$parsers[$parser])) {
            throw new \ValueError('xml_get_error_code(): Argument #1 ($parser) must be a valid XML parser');
        }

        return self::$parsers[$parser]['errorCode'];
    }

    public static function parse(
        Context $ctx,
        int $parser,
        string $data,
        bool $isFinal,
        ?Frame $frame = null
    ): bool {
        if (!isset(self::$parsers[$parser])) {
            throw new \ValueError('xml_parse(): Argument #1 ($parser) must be a valid XML parser');
        }

        if (!$isFinal) {
            return true;
        }

        $error = self::validateWellFormed($data);
        if (null === $error) {
            self::$parsers[$parser]['errorCode'] = 0;

            return true;
        }

        self::$parsers[$parser]['errorCode'] = $error['code'];
        \PHPCompiler\ext\libxml\VmLibxml::handleError($ctx, $error, $frame);

        return false;
    }

    public static function isWellFormed(string $data): bool
    {
        return null === self::validateWellFormed($data);
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
        return self::validateWellFormed($data);
    }

    /**
     * @return null|array{level: int, code: int, column: int, message: string, file: string, line: int}
     */
    private static function validateWellFormed(string $data): ?array
    {
        $trimmed = trim($data);
        if ('' === $trimmed) {
            return self::errorRecord(1, 1, 'Document is empty', 4);
        }
        if ('<' !== $trimmed[0]) {
            return self::errorRecord(1, 1, 'Start tag expected, \'<\' not found', 4);
        }

        $unclosed = self::detectUnclosedStartTag($trimmed);
        if (null !== $unclosed) {
            return $unclosed;
        }

        if (preg_match('/^<([A-Za-z_][\w:.-]*)(\s[^>]*)?\/>$/s', $trimmed)) {
            return null;
        }

        if (!preg_match('/^<([A-Za-z_][\w:.-]*)(\s[^>]*)?>(.*)<\/\1>\s*$/s', $trimmed, $matches)) {
            $premature = self::detectPrematureEnd($trimmed);
            if (null !== $premature) {
                return $premature;
            }

            return self::errorRecord(1, 1, 'Malformed XML document', 4);
        }

        return self::validateFragment($matches[3]);
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
            $end = self::findElementEnd($content, $pos);
            if (null === $end) {
                $unclosed = self::detectUnclosedStartTag(substr($content, $pos));
                if (null !== $unclosed) {
                    return $unclosed;
                }

                return self::errorRecord(1, $pos + 1, 'Malformed XML document', 4);
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
            ++$scan;
        }

        return null;
    }

    /**
     * @return array{level: int, code: int, column: int, message: string, file: string, line: int}
     */
    private static function errorRecord(
        int $line,
        int $column,
        string $message,
        int $code,
        int $level = LibxmlConstants::LIBXML_ERR_FATAL
    ): array {
        return [
            'level' => $level,
            'code' => $code,
            'column' => $column,
            'message' => $message,
            'file' => '',
            'line' => $line,
        ];
    }
}
