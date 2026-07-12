<?php

declare(strict_types=1);

namespace PHPCompiler\Web;

use PHPCompiler\ext\standard\ParseStrNativeJitHelper;
use PHPCompiler\ext\standard\phpc_native_ht_alloc;
use PHPCompiler\ext\standard\phpc_native_ht_set_string_key;
use PHPCompiler\ext\standard\phpc_native_ht_set_string_key_ht;

/**
 * User-script AOT multipart populate into native __hashtable__* (#15624, php-in-PHP).
 *
 * Avoids VM {@see HashTable} in compiled helpers (nested JIT / SIGSEGV during user-script link).
 * SSOT semantics: {@see MultipartParser}
 * php-src: main/rfc1867.c — php_parse_multipart_form_data
 */
final class MultipartNativeJitHelper
{
    private const MAX_BODY = 8_388_608;

    public static function populatePostBodyNative(
        int $postPtr,
        int $filesPtr,
        string $contentType,
        string $body
    ): void {
        if ($postPtr <= 0 || $filesPtr <= 0 || '' === $body) {
            return;
        }

        $mediaType = self::contentTypeMediaType($contentType);
        if (str_starts_with($mediaType, 'multipart/form-data')) {
            self::populateMultipartIntoNative($postPtr, $filesPtr, $contentType, $body);

            return;
        }

        ParseStrNativeJitHelper::parseIntoNative($postPtr, $body);
    }

    public static function populateMultipartIntoNative(
        int $postPtr,
        int $filesPtr,
        string $contentType,
        string $body
    ): void {
        if ($postPtr <= 0 || $filesPtr <= 0 || '' === $body || strlen($body) > self::MAX_BODY) {
            return;
        }

        $body = str_replace("\r\n", "\n", str_replace("\r", "\n", $body));
        $boundary = self::extractBoundary($contentType);
        if (null === $boundary) {
            return;
        }

        $delimiter = '--'.$boundary;
        $segments = explode($delimiter, $body);
        array_shift($segments);
        $segmentCount = \count($segments);
        for ($index = 0; $index < $segmentCount; ++$index) {
            $segment = $segments[$index];
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
                self::populateFileNative($filesPtr, $fieldName, $filename, $rawHeaders, $content);

                continue;
            }
            $params = [];
            parse_str($fieldName.'='.$content, $params);
            ParseStrNativeJitHelper::mergeIntoNative($postPtr, $params);
        }
    }

    private static function contentTypeMediaType(string $contentType): string
    {
        $contentType = strtolower(trim($contentType));
        $semi = strpos($contentType, ';');
        if (false !== $semi) {
            $contentType = substr($contentType, 0, $semi);
        }

        return trim($contentType);
    }

    private static function extractBoundary(string $contentType): ?string
    {
        if ('' === $contentType) {
            return null;
        }
        if (!preg_match('/boundary\s*=\s*(?:"([^"]+)"|([^\s;]+))/i', $contentType, $matches)) {
            return null;
        }

        return '' !== $matches[1] ? $matches[1] : $matches[2];
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private static function splitPart(string $segment): ?array
    {
        $lines = preg_split("/\r?\n/", $segment) ?: [];
        $headerLines = [];
        $contentLines = [];
        $lineCount = \count($lines);
        $index = 0;
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

    private static function populateFileNative(
        int $filesPtr,
        string $fieldName,
        string $filename,
        string $rawHeaders,
        string $content
    ): void {
        $entryPtr = (int) phpc_native_ht_alloc();
        if ($entryPtr <= 0) {
            return;
        }

        phpc_native_ht_set_string_key($entryPtr, 'name', $filename);
        $partType = self::headerValue($rawHeaders, 'Content-Type');
        phpc_native_ht_set_string_key(
            $entryPtr,
            'type',
            null !== $partType && '' !== $partType ? $partType : 'application/octet-stream'
        );

        $tmp = UploadTemp::createTempFile();
        if (false === $tmp) {
            phpc_native_ht_set_string_key($entryPtr, 'error', '1');
            phpc_native_ht_set_string_key_ht($filesPtr, $fieldName, $entryPtr);

            return;
        }
        if (false === file_put_contents($tmp, $content)) {
            @unlink($tmp);
            phpc_native_ht_set_string_key($entryPtr, 'error', '1');
            phpc_native_ht_set_string_key_ht($filesPtr, $fieldName, $entryPtr);

            return;
        }

        phpc_native_ht_set_string_key($entryPtr, 'tmp_name', $tmp);
        phpc_native_ht_set_string_key($entryPtr, 'error', '0');
        phpc_native_ht_set_string_key($entryPtr, 'size', (string) strlen($content));
        phpc_native_ht_set_string_key_ht($filesPtr, $fieldName, $entryPtr);
    }
}
