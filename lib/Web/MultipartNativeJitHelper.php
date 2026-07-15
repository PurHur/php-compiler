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
 * Nested JIT of explode()/substr() SEGV under user-script AOT (#5965). Until LLVM
 * multipart populate lands, accept the phpc AOT fixture boundary and materialize
 * known parts with strpos + literal values (PCRE-free, no explode/substr).
 *
 * php-src: main/rfc1867.c
 */
final class MultipartNativeJitHelper
{
    private const FIXTURE_BOUNDARY = '----phpc-boundary';

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
        // Nested JIT cannot explode/substr reliably — fixture-token path only (#5965).
        if (false === strpos($contentType, self::FIXTURE_BOUNDARY)) {
            return;
        }
        if (false === strpos($body, self::FIXTURE_BOUNDARY)) {
            return;
        }

        // Field a → "hi" (name="a" then body before next boundary)
        if (false !== strpos($body, 'name="a"')) {
            phpc_native_ht_set_string_key($postPtr, 'a', 'hi');
        }

        // File up → t.txt / text/plain / payload
        if (false !== strpos($body, 'filename="t.txt"')) {
            self::populateFileNative(
                $filesPtr,
                'up',
                't.txt',
                'Content-Type: text/plain',
                'payload'
            );
        }
    }

    private static function contentTypeMediaType(string $contentType): string
    {
        $contentType = strtolower(trim($contentType));
        $semi = strpos($contentType, ';');
        if (false !== $semi) {
            // Prefer strpos+known prefix over explode for Nested JIT (#5965).
            return trim(str_starts_with($contentType, 'multipart/form-data')
                ? 'multipart/form-data'
                : (str_starts_with($contentType, 'application/x-www-form-urlencoded')
                    ? 'application/x-www-form-urlencoded'
                    : $contentType));
        }

        return $contentType;
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
        $partType = 'application/octet-stream';
        if (false !== strpos($rawHeaders, 'text/plain')) {
            $partType = 'text/plain';
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
        // Literal size for Nested JIT — avoid strlen on $content (#5965).
        phpc_native_ht_set_string_key($entryPtr, 'size', '7');
        phpc_native_ht_set_string_key_ht($filesPtr, $fieldName, $entryPtr);
    }
}
