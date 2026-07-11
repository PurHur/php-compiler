<?php

declare(strict_types=1);

namespace PHPCompiler\Web;

use PHPCompiler\ext\standard\VmParseStr;
use PHPCompiler\VM\HashTable;

/**
 * multipart/form-data parsing for CGI superglobals (VM path).
 *
 * Bracket field names delegate to parse_str/VmParseStr merge semantics; AOT refresh
 * uses the same rules via SuperglobalRefreshJitHelper PHP (#7302, #9907, #13429).
 *
 * php-src: main/rfc1867.c, main/php_variables.c
 */
final class MultipartParser
{
    public static function populate(HashTable $post, HashTable $files, string $body): void
    {
        if ('' === $body || strlen($body) > DevServer::maxRequestBody()) {
            return;
        }
        $body = str_replace("\r\n", "\n", str_replace("\r", "\n", $body));
        $boundary = self::extractBoundary();
        if (null === $boundary) {
            return;
        }
        $delimiter = '--'.$boundary;
        $segments = explode($delimiter, $body);
        array_shift($segments);
        foreach ($segments as $segment) {
            $segment = ltrim($segment, "\r\n");
            if ('' === $segment || str_starts_with($segment, '--')) {
                continue;
            }
            if (str_ends_with($segment, '--')) {
                $segment = substr($segment, 0, -2);
            }
            $segment = rtrim($segment, "\r\n");
            $part = self::splitPart($segment);
            if (null === $part) {
                continue;
            }
            [$rawHeaders, $content] = $part;
            $disposition = self::headerValue($rawHeaders, 'Content-Disposition');
            if (null === $disposition) {
                continue;
            }
            $fieldName = self::paramValue($disposition, 'name');
            if (null === $fieldName || '' === $fieldName) {
                continue;
            }
            $filename = self::paramValue($disposition, 'filename');
            if (null !== $filename) {
                self::populateFile($files, $fieldName, $filename, $rawHeaders, $content);

                continue;
            }
            $params = [];
            parse_str($fieldName.'='.$content, $params);
            VmParseStr::mergeInto($post, $params);
        }
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private static function splitPart(string $segment): ?array
    {
        $lines = preg_split("/\r?\n/", $segment) ?: [];
        $headerLines = [];
        $contentLines = [];
        $index = 0;
        $lineCount = 0;
        foreach ($lines as $_) {
            ++$lineCount;
        }
        while ($index < $lineCount) {
            if ('' === trim($lines[$index], "\r\n")) {
                $peek = $index + 1;
                while ($peek < $lineCount && '' === trim($lines[$peek], "\r\n")) {
                    ++$peek;
                }
                if ($peek < $lineCount && str_contains($lines[$peek], ':')) {
                    ++$index;

                    continue;
                }
                ++$index;

                break;
            }
            $headerLines[] = $lines[$index];
            ++$index;
        }
        while ($index < $lineCount) {
            $contentLines[] = $lines[$index];
            ++$index;
        }
        if ([] === $headerLines || [] === $contentLines) {
            return null;
        }

        return [implode("\n", $headerLines), trim(implode("\n", $contentLines), "\r\n")];
    }

    private static function extractBoundary(): ?string
    {
        $contentType = getenv('CONTENT_TYPE');
        if (false === $contentType || '' === $contentType) {
            $contentType = getenv('HTTP_CONTENT_TYPE');
        }
        if (false === $contentType || '' === $contentType) {
            return null;
        }
        if (!preg_match('/boundary\s*=\s*(?:"([^"]+)"|([^\s;]+))/i', $contentType, $matches)) {
            return null;
        }

        return '' !== $matches[1] ? $matches[1] : $matches[2];
    }

    private static function headerValue(string $rawHeaders, string $name): ?string
    {
        foreach (preg_split("/\r?\n/", $rawHeaders) ?: [] as $line) {
            $line = trim($line, "\r\n");
            if ('' === $line || !str_contains($line, ':')) {
                continue;
            }
            [$headerName, $value] = explode(':', $line, 2);
            if (0 === strcasecmp(trim($headerName), $name)) {
                return trim($value, "\r\n ");
            }
        }

        return null;
    }

    private static function paramValue(string $disposition, string $param): ?string
    {
        if (!preg_match('/'.preg_quote($param, '/').'\s*=\s*"([^"]*)"/i', $disposition, $matches)) {
            return null;
        }

        return $matches[1];
    }

    private static function populateFile(
        HashTable $files,
        string $fieldName,
        string $filename,
        string $rawHeaders,
        string $content
    ): void {
        $entry = VmParseStr::ensureArrayChild($files, $fieldName);
        self::setStringEntry($entry, 'name', $filename);
        $partType = self::headerValue($rawHeaders, 'Content-Type');
        self::setStringEntry(
            $entry,
            'type',
            null !== $partType && '' !== $partType ? $partType : 'application/octet-stream'
        );
        $tmp = UploadTemp::createTempFile();
        if (false === $tmp) {
            VmParseStr::setScalarEntry($entry, 'error', 1);

            return;
        }
        if (false === file_put_contents($tmp, $content)) {
            @unlink($tmp);
            VmParseStr::setScalarEntry($entry, 'error', 1);

            return;
        }
        self::setStringEntry($entry, 'tmp_name', $tmp);
        VmParseStr::setScalarEntry($entry, 'error', 0);
        VmParseStr::setScalarEntry($entry, 'size', strlen($content));
    }

    private static function setStringEntry(HashTable $ht, string $key, string $value): void
    {
        VmParseStr::setScalarEntry($ht, $key, $value);
    }
}
