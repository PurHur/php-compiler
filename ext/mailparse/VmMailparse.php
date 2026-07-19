<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mailparse;

use PHPCompiler\ext\standard\VmJson;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * MIME / RFC822 parse helpers (PECL mailparse mailparse.c / mailparse_rfc822.c; #6383).
 *
 * Pure PHP incremental header parser for Phase 1 — no libmailparse / runtime/*.c.
 */
final class VmMailparse
{
    public const CLASS_LC = 'mailparse_mail_resource';

    public const CLASS_NAME = 'mailparse_mail_resource';

    /**
     * @var array<int, array{
     *   buffer: string,
     *   headers: array<string, string>,
     *   body: string,
     *   headers_complete: bool,
     *   closed: bool
     * }>
     */
    private static array $state = [];

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC])) {
            return;
        }
        $entry = new ClassEntry(self::CLASS_NAME);
        $entry->isInternal = true;
        $ctx->classes[self::CLASS_LC] = $entry;
    }

    public static function create(Context $ctx): Variable
    {
        self::registerClass($ctx);
        $object = new ObjectEntry($ctx->classes[self::CLASS_LC]);
        $object->constructed = true;
        self::$state[$object->id] = [
            'buffer' => '',
            'headers' => [],
            'body' => '',
            'headers_complete' => false,
            'closed' => false,
        ];
        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($object);

        return $var;
    }

    public static function free(ObjectEntry $object): bool
    {
        if (!isset(self::$state[$object->id]) || self::$state[$object->id]['closed']) {
            return false;
        }
        self::$state[$object->id]['closed'] = true;
        unset(self::$state[$object->id]);

        return true;
    }

    public static function parse(ObjectEntry $object, string $data): bool
    {
        $row = &self::$state[$object->id];
        $row['buffer'] .= $data;
        self::reparseBuffer($row);

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPartData(ObjectEntry $object): array
    {
        $row = self::$state[$object->id];
        $headers = $row['headers'];
        $buffer = $row['buffer'];
        $bodyStart = self::bodyStartOffset($buffer);
        $ending = \strlen($buffer);
        $body = $row['body'];
        $contentType = $headers['content-type'] ?? 'text/plain';
        if (false !== ($semi = \strpos($contentType, ';'))) {
            $contentType = \trim(\substr($contentType, 0, $semi));
        }
        $charset = self::headerParam($headers['content-type'] ?? '', 'charset') ?? 'us-ascii';
        $transfer = $headers['content-transfer-encoding'] ?? '8bit';

        return [
            'headers' => $headers,
            'starting-pos' => 0,
            'starting-pos-body' => false === $bodyStart ? $ending : $bodyStart,
            'ending-pos' => $ending,
            'ending-pos-body' => $ending,
            'line-count' => self::countLines($buffer),
            'body-line-count' => self::countLines($body),
            'charset' => $charset,
            'transfer-encoding' => $transfer,
            'content-type' => $contentType,
        ];
    }

    /**
     * @return list<array{display: string, address: string, is_group: bool}>
     */
    public static function parseAddresses(string $addresses): array
    {
        $addresses = \trim($addresses);
        if ('' === $addresses) {
            return [];
        }
        $parts = self::splitAddressList($addresses);
        $out = [];
        foreach ($parts as $part) {
            $part = \trim($part);
            if ('' === $part) {
                continue;
            }
            $isGroup = false;
            if (\preg_match('/^([^:]+):\s*(.*);\s*$/s', $part, $gm)) {
                $isGroup = true;
                $display = \trim($gm[1]);
                $address = \trim($gm[2]);
                if ('' === $address) {
                    $address = $display;
                }
            } elseif (\preg_match('/^(.*)<([^>]+)>\s*$/s', $part, $m)) {
                $display = \trim($m[1], " \t\"'");
                $address = \trim($m[2]);
                if ('' === $display) {
                    $display = $address;
                }
            } else {
                $address = $part;
                $display = $part;
            }
            $out[] = [
                'display' => $display,
                'address' => $address,
                'is_group' => $isGroup,
            ];
        }

        return $out;
    }

    public static function requireMsgArg(Variable $operand, string $function, int $argIndex = 0): ObjectEntry
    {
        $operand = $operand->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $operand->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($mimemail) must be of type resource, %s given',
                $function,
                $argIndex + 1,
                self::typeName($operand)
            ));
        }
        $object = $operand->toObject();
        if (!self::isMsgObject($object) || !isset(self::$state[$object->id]) || self::$state[$object->id]['closed']) {
            throw new \TypeError(
                $function.'(): supplied resource is not a valid mailparse_mail_resource resource'
            );
        }

        return $object;
    }

    public static function isMsgObject(?ObjectEntry $object): bool
    {
        return null !== $object && self::CLASS_LC === \strtolower($object->class->name);
    }

    public static function partDataVariable(ObjectEntry $object): Variable
    {
        return VmJson::import(self::getPartData($object));
    }

    public static function addressesVariable(string $addresses): Variable
    {
        return VmJson::import(self::parseAddresses($addresses));
    }

    /**
     * @param array{
     *   buffer: string,
     *   headers: array<string, string>,
     *   body: string,
     *   headers_complete: bool,
     *   closed: bool
     * } $row
     */
    private static function reparseBuffer(array &$row): void
    {
        $buffer = $row['buffer'];
        $bodyStart = self::bodyStartOffset($buffer);
        if (false === $bodyStart) {
            $row['headers'] = self::parseHeaderBlock($buffer);
            $row['body'] = '';
            $row['headers_complete'] = false;

            return;
        }
        $headerBlock = \substr($buffer, 0, $bodyStart);
        $row['headers'] = self::parseHeaderBlock($headerBlock);
        $row['body'] = \substr($buffer, $bodyStart);
        $row['headers_complete'] = true;
    }

    /**
     * @return array<string, string>
     */
    private static function parseHeaderBlock(string $block): array
    {
        $block = \str_replace(["\r\n", "\r"], "\n", $block);
        $block = \rtrim($block, "\n");
        if ('' === $block) {
            return [];
        }
        $lines = \explode("\n", $block);
        $headers = [];
        $currentKey = null;
        foreach ($lines as $line) {
            if ('' === $line) {
                break;
            }
            if (null !== $currentKey && (isset($line[0]) && (' ' === $line[0] || "\t" === $line[0]))) {
                $headers[$currentKey] .= ' '.\trim($line);
                continue;
            }
            $colon = \strpos($line, ':');
            if (false === $colon) {
                continue;
            }
            $key = \strtolower(\trim(\substr($line, 0, $colon)));
            $value = \trim(\substr($line, $colon + 1));
            if (isset($headers[$key])) {
                $headers[$key] .= ', '.$value;
            } else {
                $headers[$key] = $value;
            }
            $currentKey = $key;
        }

        return $headers;
    }

    /** @return int|false byte offset of body start (after blank line) */
    private static function bodyStartOffset(string $buffer): int|false
    {
        $crlf = \strpos($buffer, "\r\n\r\n");
        if (false !== $crlf) {
            return $crlf + 4;
        }
        $lf = \strpos($buffer, "\n\n");
        if (false !== $lf) {
            return $lf + 2;
        }

        return false;
    }

    private static function headerParam(string $headerValue, string $name): ?string
    {
        if ('' === $headerValue) {
            return null;
        }
        if (\preg_match('/(?:^|;)\s*'.\preg_quote($name, '/').'\s*=\s*"?([^";\s]+)"?/i', $headerValue, $m)) {
            return $m[1];
        }

        return null;
    }

    private static function countLines(string $text): int
    {
        if ('' === $text) {
            return 0;
        }

        return \substr_count(\str_replace(["\r\n", "\r"], "\n", $text), "\n") + 1;
    }

    /**
     * @return list<string>
     */
    private static function splitAddressList(string $addresses): array
    {
        $parts = [];
        $current = '';
        $len = \strlen($addresses);
        $inQuotes = false;
        $angleDepth = 0;
        for ($i = 0; $i < $len; ++$i) {
            $ch = $addresses[$i];
            if ('"' === $ch && (0 === $i || '\\' !== $addresses[$i - 1])) {
                $inQuotes = !$inQuotes;
                $current .= $ch;
                continue;
            }
            if (!$inQuotes) {
                if ('<' === $ch) {
                    ++$angleDepth;
                } elseif ('>' === $ch && $angleDepth > 0) {
                    --$angleDepth;
                } elseif (',' === $ch && 0 === $angleDepth) {
                    $parts[] = $current;
                    $current = '';
                    continue;
                }
            }
            $current .= $ch;
        }
        if ('' !== \trim($current)) {
            $parts[] = $current;
        }

        return $parts;
    }

    private static function typeName(Variable $operand): string
    {
        switch ($operand->type) {
            case Variable::TYPE_NULL:
                return 'null';
            case Variable::TYPE_INTEGER:
                return 'int';
            case Variable::TYPE_FLOAT:
                return 'float';
            case Variable::TYPE_BOOLEAN:
                return 'bool';
            case Variable::TYPE_STRING:
                return 'string';
            case Variable::TYPE_ARRAY:
                return 'array';
            case Variable::TYPE_OBJECT:
                return $operand->toObject()->class->name;
            default:
                return 'unknown';
        }
    }
}
