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
    /** @var array<int, true> */
    private static array $parsers = [];

    private static int $nextParserId = 0;

    public static function parserCreate(): int
    {
        $id = ++self::$nextParserId;
        self::$parsers[$id] = true;

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
            return true;
        }

        \PHPCompiler\ext\libxml\VmLibxml::handleError($ctx, $error, $frame);

        return false;
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

        if (preg_match('/^<([A-Za-z_][\w:.-]*)(\s[^>]*)?\/>$/s', $trimmed)) {
            return null;
        }

        if (!preg_match('/^<([A-Za-z_][\w:.-]*)(\s[^>]*)?>(.*)<\/\1>\s*$/s', $trimmed, $matches)) {
            if (preg_match('/^<([A-Za-z_][\w:.-]*)(\s[^>]*)?>/', $trimmed, $open)
                && !str_contains($trimmed, '</'.$open[1].'>')) {
                return self::errorRecord(1, \strlen($trimmed), 'Premature end of data in tag '.$open[1], 76);
            }

            return self::errorRecord(1, 1, 'Malformed XML document', 4);
        }

        return self::validateFragment($matches[3]);
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
            $end = self::findElementEnd($content, $pos);
            if (null === $end) {
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
            ++$scan;
        }

        return null;
    }

    /**
     * @return array{level: int, code: int, column: int, message: string, file: string, line: int}
     */
    private static function errorRecord(int $line, int $column, string $message, int $code): array
    {
        return [
            'level' => LibxmlConstants::LIBXML_ERR_ERROR,
            'code' => $code,
            'column' => $column,
            'message' => $message,
            'file' => '',
            'line' => $line,
        ];
    }
}
