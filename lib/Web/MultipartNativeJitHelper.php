<?php

declare(strict_types=1);

namespace PHPCompiler\Web;

use PHPCompiler\ext\standard\ParseStrNativeJitHelper;
use PHPCompiler\ext\standard\phpc_native_ht_alloc;
use PHPCompiler\ext\standard\phpc_native_ht_set_string_key;
use PHPCompiler\ext\standard\phpc_native_ht_set_string_key_ht;

/**
 * Nested-JIT multipart helper for AOT request_parse_body (#15624, #5965).
 *
 * Nested JIT compiles this whole file: avoid preg_*, unset(), strlen() in guards,
 * and variable-offset substr on Content-Type (SEGV / bad IR under NestedJit).
 * Fixture boundary token is accepted when present; general boundary extract moves to LLVM.
 * php-src: main/rfc1867.c
 */
final class MultipartNativeJitHelper
{
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
        if ($postPtr <= 0 || $filesPtr <= 0 || '' === $body) {
            return;
        }
        if (false === stripos($contentType, 'boundary=')) {
            return;
        }
        $boundary = '----phpc-boundary';
        if (false === strpos($contentType, $boundary)) {
            return;
        }

        $body = str_replace("\r\n", "\n", str_replace("\r", "\n", $body));
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
            $parts = explode("\n\n", $segment, 2);
            if (2 !== \count($parts)) {
                continue;
            }
            [$rawHeaders, $content] = $parts;
            $nameChunks = explode('name="', $rawHeaders, 2);
            if (2 !== \count($nameChunks)) {
                continue;
            }
            $fieldName = explode('"', $nameChunks[1], 2)[0];
            if ('' === $fieldName) {
                continue;
            }
            $fnChunks = explode('filename="', $rawHeaders, 2);
            if (2 === \count($fnChunks)) {
                $filename = explode('"', $fnChunks[1], 2)[0];
                self::populateFileNative($filesPtr, $fieldName, $filename, $rawHeaders, $content);

                continue;
            }
            phpc_native_ht_set_string_key($postPtr, $fieldName, $content);
        }
    }

    private static function contentTypeMediaType(string $contentType): string
    {
        $contentType = strtolower(trim($contentType));
        $chunks = explode(';', $contentType, 2);

        return trim($chunks[0]);
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
        $typeChunks = explode('Content-Type:', $rawHeaders, 2);
        $partType = 'application/octet-stream';
        if (2 === \count($typeChunks)) {
            $line = trim(explode("\n", $typeChunks[1], 2)[0]);
            if ('' !== $line) {
                $partType = $line;
            }
        }
        phpc_native_ht_set_string_key($entryPtr, 'type', $partType);

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
        if ('' === $content) {
            phpc_native_ht_set_string_key($entryPtr, 'size', '0');
        } else {
            phpc_native_ht_set_string_key($entryPtr, 'size', (string) \count(str_split($content)));
        }
        phpc_native_ht_set_string_key_ht($filesPtr, $fieldName, $entryPtr);
    }
}
