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

        $inner = $matches[3];
        if ('' !== $inner && '<' === $inner[0]) {
            $nested = self::validateWellFormed('<'.$matches[1].'>'.$inner.'</'.$matches[1].'>');

            return $nested;
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
