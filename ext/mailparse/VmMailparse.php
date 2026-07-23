<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mailparse;

use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\ext\standard\VmJson;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * MIME / RFC822 parse helpers (PECL mailparse mailparse.c / php_mailparse_mime.c; #6383, #22230).
 *
 * Pure PHP MIME tree — multipart structure / get_part / extract_* / parse_file + transfer helpers.
 * No libmailparse / runtime/*.c.
 */
final class VmMailparse
{
    public const CLASS_LC = 'mailparse_mail_resource';

    public const CLASS_NAME = 'mailparse_mail_resource';

    private const DECODE_NONE = 0;
    private const DECODE_8BIT = 1;
    private const DECODE_NOHEADERS = 2;

    /** @var array<int, array<string, mixed>> */
    private static array $state = [];

    /** @var array<int, array<string, int>> root object id → section id → object id */
    private static array $sectionIndex = [];

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
        self::$state[$object->id] = self::emptyPartState($object->id, '1');
        self::$sectionIndex[$object->id] = ['1' => $object->id];
        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($object);

        return $var;
    }

    public static function free(ObjectEntry $object): bool
    {
        if (!isset(self::$state[$object->id]) || self::$state[$object->id]['closed']) {
            return false;
        }
        $rootId = (int) self::$state[$object->id]['root_id'];
        self::$state[$object->id]['closed'] = true;
        unset(self::$state[$object->id]);
        if ($object->id === $rootId) {
            if (isset(self::$sectionIndex[$rootId])) {
                foreach (self::$sectionIndex[$rootId] as $oid) {
                    if ($oid !== $rootId && isset(self::$state[$oid])) {
                        self::$state[$oid]['closed'] = true;
                        unset(self::$state[$oid]);
                    }
                }
                unset(self::$sectionIndex[$rootId]);
            }
        }

        return true;
    }

    public static function parse(ObjectEntry $object, string $data): bool
    {
        $rootId = (int) self::$state[$object->id]['root_id'];
        if (!isset(self::$state[$rootId]) || self::$state[$rootId]['closed']) {
            return false;
        }
        self::$state[$rootId]['buffer'] .= $data;
        self::rebuildTreeFromRoot($object->class, $rootId);

        return true;
    }

    public static function parseFile(Context $ctx, string $filename): Variable|false
    {
        $raw = @\file_get_contents($filename);
        if (false === $raw) {
            return false;
        }
        $msg = self::create($ctx);
        self::parse($msg->toObject(), $raw);

        return $msg;
    }

    /** @return list<string> */
    public static function getStructure(ObjectEntry $object): array
    {
        $rootId = (int) self::$state[$object->id]['root_id'];
        $root = self::$state[$rootId];

        return self::enumStructureIds($root, [1]);
    }

    public static function getPart(Context $ctx, ObjectEntry $object, string $section): ObjectEntry|false
    {
        $rootId = (int) self::$state[$object->id]['root_id'];
        $index = self::$sectionIndex[$rootId] ?? [];
        if (!isset($index[$section]) || !isset(self::$state[$index[$section]])) {
            return false;
        }
        $partId = $index[$section];
        self::registerClass($ctx);
        $alias = new ObjectEntry($ctx->classes[self::CLASS_LC]);
        $alias->constructed = true;
        // Mirror part fields onto the alias object id (PECL returns a refcounted handle).
        self::$state[$alias->id] = self::$state[$partId];
        self::$state[$alias->id]['root_id'] = $rootId;

        return $alias;
    }

    /** @return array<string, mixed> */
    public static function getPartData(ObjectEntry $object): array
    {
        $row = self::$state[$object->id];
        $headers = $row['headers'];
        $rootBuffer = (string) self::$state[(int) $row['root_id']]['buffer'];
        $out = [
            'headers' => $headers,
            'starting-pos' => $row['startpos'],
            'starting-pos-body' => $row['bodystart'],
            'ending-pos' => $row['endpos'],
            'ending-pos-body' => $row['bodyend'],
            'line-count' => self::countLines(\substr(
                $rootBuffer,
                (int) $row['startpos'],
                \max(0, (int) $row['endpos'] - (int) $row['startpos'])
            )),
            'body-line-count' => self::countLines((string) $row['body']),
            'charset' => $row['charset'],
            'transfer-encoding' => $row['transfer_encoding'],
            'content-type' => $row['content_type'],
        ];
        if (null !== $row['boundary']) {
            $out['content-boundary'] = $row['boundary'];
        }
        $ctHeader = $headers['content-type'] ?? '';
        if ('' !== $ctHeader) {
            foreach (self::headerParams($ctHeader) as $k => $v) {
                $out['content-'.$k] = $v;
            }
        }
        $cdHeader = $headers['content-disposition'] ?? '';
        if ('' !== $cdHeader) {
            if (false !== ($semi = \strpos($cdHeader, ';'))) {
                $out['content-disposition'] = \trim(\substr($cdHeader, 0, $semi));
            } else {
                $out['content-disposition'] = $cdHeader;
            }
            foreach (self::headerParams($cdHeader) as $k => $v) {
                $out['disposition-'.$k] = $v;
            }
        }

        return $out;
    }

    /**
     * @param callable(string):void|null $onChunk
     */
    public static function extract(
        ObjectEntry $object,
        string $sourceData,
        int $decodeFlags,
        bool $returnString,
        ?callable $onChunk
    ): string|bool {
        $row = self::$state[$object->id];
        $start = ($decodeFlags & self::DECODE_NOHEADERS) ? (int) $row['bodystart'] : (int) $row['startpos'];
        $end = (int) $row['bodyend'];
        if ($start < 0 || $end < $start) {
            return false;
        }
        $slice = \substr($sourceData, $start, $end - $start);
        if ($decodeFlags & self::DECODE_8BIT) {
            $slice = self::decodeTransfer($slice, (string) $row['transfer_encoding']);
        }
        if (null !== $onChunk) {
            $onChunk($slice);

            return true;
        }
        if ($returnString) {
            return $slice;
        }
        echo $slice;

        return true;
    }

    /**
     * @param callable(string):void|null $onChunk
     */
    public static function extractFromFile(
        ObjectEntry $object,
        string $filename,
        int $decodeFlags,
        bool $returnString,
        ?callable $onChunk
    ): string|bool {
        $raw = @\file_get_contents($filename);
        if (false === $raw) {
            return false;
        }

        return self::extract($object, $raw, $decodeFlags, $returnString, $onChunk);
    }

    public static function extractDecodeBodyFlags(): int
    {
        return self::DECODE_8BIT | self::DECODE_NOHEADERS;
    }

    public static function extractDecodeWholeFlags(): int
    {
        return self::DECODE_NONE;
    }

    public static function determineBestXferEncoding(int $streamHandle): string|false
    {
        VmFs::fseek($streamHandle, 0, \SEEK_SET);
        $best = '7bit';
        $linelen = 0;
        $longline = false;
        while (!VmFs::feof($streamHandle)) {
            $chunk = VmFs::fread($streamHandle, 4096);
            if (false === $chunk || '' === $chunk) {
                break;
            }
            $len = \strlen($chunk);
            for ($i = 0; $i < $len; ++$i) {
                $c = \ord($chunk[$i]);
                if ($c > 0x80) {
                    $best = '8bit';
                } elseif (0 === $c) {
                    VmFs::fseek($streamHandle, 0, \SEEK_SET);

                    return 'BASE64';
                }
                if ("\n" === $chunk[$i]) {
                    $linelen = 0;
                } elseif (++$linelen > 200) {
                    $longline = true;
                }
            }
        }
        if ($longline) {
            $best = 'quoted-printable';
        }
        VmFs::fseek($streamHandle, 0, \SEEK_SET);

        return $best;
    }

    public static function streamEncode(int $srcHandle, int $destHandle, string $encoding): bool
    {
        $enc = \strtolower(\trim($encoding));
        VmFs::fseek($srcHandle, 0, \SEEK_SET);
        $data = '';
        while (!VmFs::feof($srcHandle)) {
            $chunk = VmFs::fread($srcHandle, 8192);
            if (false === $chunk || '' === $chunk) {
                break;
            }
            $data .= $chunk;
        }
        $encoded = match ($enc) {
            '7bit', '8bit', 'binary' => $data,
            'base64' => \chunk_split(\base64_encode($data)),
            'quoted-printable' => \quoted_printable_encode($data),
            default => null,
        };
        if (null === $encoded) {
            return false;
        }
        $written = VmFs::fwrite($destHandle, $encoded);

        return false !== $written;
    }

    /** @return list<array<string, string>>|false */
    public static function uudecodeAll(int $streamHandle): array|false
    {
        VmFs::fseek($streamHandle, 0, \SEEK_SET);
        $stripped = '';
        $parts = [];
        $nparts = 0;
        $tmpBase = \sys_get_temp_dir().'/mailparse_uue_'.\getmypid().'_'.\mt_rand();
        while (false !== ($line = VmFs::fgets($streamHandle))) {
            if (0 === \strncmp($line, 'begin ', 6)) {
                $orig = \rtrim(\substr($line, \strlen($line) > 10 ? 10 : \strlen($line)), "\r\n");
                if (0 === $nparts) {
                    $parts[] = ['filename' => $tmpBase.'_main.txt'];
                }
                $partPath = $tmpBase.'_part'.$nparts.'.bin';
                $decoded = self::uudecodeFromStream($streamHandle);
                \file_put_contents($partPath, $decoded);
                $parts[] = [
                    'origfilename' => $orig,
                    'filename' => $partPath,
                ];
                ++$nparts;
            } else {
                $stripped .= $line;
            }
        }
        VmFs::fseek($streamHandle, 0, \SEEK_SET);
        if (0 === $nparts) {
            return false;
        }
        \file_put_contents($parts[0]['filename'], $stripped);

        return $parts;
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

    public static function structureVariable(ObjectEntry $object): Variable
    {
        return VmJson::import(self::getStructure($object));
    }

    public static function uudecodeVariable(array $parts): Variable
    {
        return VmJson::import($parts);
    }

    /** @return list<array{display: string, address: string, is_group: bool}> */
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

    /** @return array<string, mixed> */
    private static function emptyPartState(int $rootId, string $section): array
    {
        return [
            'buffer' => '',
            'headers' => [],
            'body' => '',
            'headers_complete' => false,
            'closed' => false,
            'root_id' => $rootId,
            'section' => $section,
            'is_preamble' => false,
            'children' => [],
            'startpos' => 0,
            'bodystart' => 0,
            'endpos' => 0,
            'bodyend' => 0,
            'content_type' => 'text/plain',
            'boundary' => null,
            'transfer_encoding' => '8bit',
            'charset' => 'us-ascii',
        ];
    }

    private static function rebuildTreeFromRoot(ClassEntry $class, int $rootId): void
    {
        $buffer = (string) self::$state[$rootId]['buffer'];
        if (isset(self::$sectionIndex[$rootId])) {
            foreach (self::$sectionIndex[$rootId] as $oid) {
                if ($oid !== $rootId && isset(self::$state[$oid])) {
                    unset(self::$state[$oid]);
                }
            }
        }
        self::$sectionIndex[$rootId] = ['1' => $rootId];
        self::fillPartFromSlice($class, $rootId, $rootId, '1', $buffer, 0, \strlen($buffer), false);
    }

    private static function fillPartFromSlice(
        ClassEntry $class,
        int $rootId,
        int $partId,
        string $section,
        string $buffer,
        int $absStart,
        int $absEnd,
        bool $isPreamble
    ): void {
        $slice = \substr($buffer, $absStart, \max(0, $absEnd - $absStart));
        $bodyRel = self::bodyStartOffset($slice);
        $headers = [];
        $body = '';
        $headersComplete = false;
        $bodystart = $absStart;
        if (false === $bodyRel) {
            $headers = self::parseHeaderBlock($slice);
        } else {
            $headers = self::parseHeaderBlock(\substr($slice, 0, $bodyRel));
            $body = \substr($slice, $bodyRel);
            $headersComplete = true;
            $bodystart = $absStart + $bodyRel;
        }
        $ctFull = $headers['content-type'] ?? 'text/plain';
        $contentType = $ctFull;
        if (false !== ($semi = \strpos($contentType, ';'))) {
            $contentType = \trim(\substr($contentType, 0, $semi));
        }
        $contentType = \strtolower($contentType);
        $boundary = self::headerParam($ctFull, 'boundary');
        $charset = self::headerParam($ctFull, 'charset') ?? 'us-ascii';
        $transfer = \strtolower($headers['content-transfer-encoding'] ?? '8bit');

        self::$state[$partId] = [
            'buffer' => ($partId === $rootId) ? $buffer : '',
            'headers' => $headers,
            'body' => $body,
            'headers_complete' => $headersComplete,
            'closed' => false,
            'root_id' => $rootId,
            'section' => $section,
            'is_preamble' => $isPreamble,
            'children' => [],
            'startpos' => $absStart,
            'bodystart' => $bodystart,
            'endpos' => $absEnd,
            'bodyend' => $absEnd,
            'content_type' => $contentType,
            'boundary' => $boundary,
            'transfer_encoding' => $transfer,
            'charset' => $charset,
        ];
        self::$sectionIndex[$rootId][$section] = $partId;

        if (null === $boundary || !\str_starts_with($contentType, 'multipart/') || '' === $body) {
            return;
        }

        $children = self::splitMultipartBody($body, $boundary, $bodystart);
        // PECL multipart enum starts child ids at 0 (preamble skipped in get_structure).
        $childNum = 0;
        foreach ($children as $child) {
            $childSection = $section.'.'.$childNum;
            $childObj = new ObjectEntry($class);
            $childObj->constructed = true;
            $childId = $childObj->id;
            self::fillPartFromSlice(
                $class,
                $rootId,
                $childId,
                $childSection,
                $buffer,
                $child['start'],
                $child['end'],
                $child['preamble']
            );
            self::$state[$partId]['children'][] = $childId;
            ++$childNum;
        }
    }

    /**
     * @return list<array{start: int, end: int, preamble: bool}>
     */
    private static function splitMultipartBody(string $body, string $boundary, int $bodyAbsStart): array
    {
        $delim = '--'.$boundary;
        $parts = [];
        $len = \strlen($body);
        $pos = 0;
        $first = true;
        while ($pos < $len) {
            $at = \strpos($body, $delim, $pos);
            if (false === $at) {
                break;
            }
            if (0 !== $at) {
                $prev = $body[$at - 1];
                if ("\n" !== $prev && "\r" !== $prev) {
                    $pos = $at + 1;
                    continue;
                }
            }
            if ($first) {
                // PECL always inserts a preamble child before the first boundary so
                // enum_parts skips id 0 and real parts start at 1.1 (#22230).
                $parts[] = [
                    'start' => $bodyAbsStart,
                    'end' => $bodyAbsStart + $at,
                    'preamble' => true,
                ];
            }
            $first = false;
            $after = $at + \strlen($delim);
            if ($after + 1 < $len && '-' === $body[$after] && '-' === $body[$after + 1]) {
                break;
            }
            while ($after < $len && (' ' === $body[$after] || "\t" === $body[$after])) {
                ++$after;
            }
            if ($after < $len && "\r" === $body[$after]) {
                ++$after;
            }
            if ($after < $len && "\n" === $body[$after]) {
                ++$after;
            }
            $next = $after;
            while (true) {
                $cand = \strpos($body, $delim, $next);
                if (false === $cand) {
                    $next = $len;
                    break;
                }
                if (0 === $cand || "\n" === $body[$cand - 1] || "\r" === $body[$cand - 1]) {
                    $next = $cand;
                    break;
                }
                $next = $cand + 1;
            }
            $end = $next;
            if ($end > $after && "\n" === $body[$end - 1]) {
                --$end;
                if ($end > $after && "\r" === $body[$end - 1]) {
                    --$end;
                }
            }
            $parts[] = [
                'start' => $bodyAbsStart + $after,
                'end' => $bodyAbsStart + $end,
                'preamble' => false,
            ];
            $pos = $next;
        }

        return $parts;
    }

    /**
     * @param list<int> $idPath
     * @return list<string>
     */
    private static function enumStructureIds(array $part, array $idPath): array
    {
        $out = [\implode('.', $idPath)];
        $children = $part['children'];
        $isMultipart = \str_starts_with((string) $part['content_type'], 'multipart/');
        $nextId = $isMultipart ? 0 : 1;
        foreach ($children as $childId) {
            if (!isset(self::$state[$childId])) {
                ++$nextId;
                continue;
            }
            $child = self::$state[$childId];
            if ($nextId > 0) {
                $childPath = $idPath;
                $childPath[] = $nextId;
                $out = \array_merge($out, self::enumStructureIds($child, $childPath));
            }
            ++$nextId;
        }

        return $out;
    }

    private static function decodeTransfer(string $data, string $encoding): string
    {
        $encoding = \strtolower(\trim($encoding));

        return match ($encoding) {
            'base64' => (string) \base64_decode(\preg_replace('/\s+/', '', $data) ?? $data, true),
            'quoted-printable' => \quoted_printable_decode($data),
            default => $data,
        };
    }

    private static function uudecodeFromStream(int $streamHandle): string
    {
        $out = '';
        while (false !== ($line = VmFs::fgets($streamHandle))) {
            $trimmed = \rtrim($line, "\r\n");
            if ('end' === $trimmed) {
                break;
            }
            if ('' === $trimmed || '`' === $trimmed) {
                continue;
            }
            $decoded = \convert_uudecode($trimmed."\n");
            if (false !== $decoded) {
                $out .= $decoded;
            }
        }

        return $out;
    }

    /** @return array<string, string> */
    private static function headerParams(string $headerValue): array
    {
        $out = [];
        if (\preg_match_all('/(?:^|;)\s*([^=\s;]+)\s*=\s*(?:"([^"]*)"|([^";\s]+))/', $headerValue, $m, \PREG_SET_ORDER)) {
            foreach ($m as $match) {
                $name = \strtolower($match[1]);
                $val = $match[2] !== '' ? $match[2] : ($match[3] ?? '');
                $out[$name] = $val;
            }
        }

        return $out;
    }

    /** @return array<string, string> */
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

    /** @return int|false */
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

    /** @return list<string> */
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
