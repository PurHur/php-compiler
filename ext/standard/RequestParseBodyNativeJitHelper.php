<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Web\MultipartNativeJitHelper;

/**
 * User-script AOT request_parse_body() native materializer (#5965, #16927).
 *
 * Avoids nested-JIT of {@see RequestParseBodyEngine} (segfault at main_after_init).
 * SSOT semantics: {@see RequestParseBodyEngine}
 * php-src: ext/standard/http.c — request_parse_body
 */
final class RequestParseBodyNativeJitHelper
{
    private static bool $consumed = false;

    public static function parseIntoNative(int $postPtr, int $filesPtr, ?array $options = null): void
    {
        unset($options);

        if (self::$consumed) {
            return;
        }
        self::$consumed = true;

        $contentType = self::overlayGetenv('CONTENT_TYPE');
        if (false === $contentType || '' === $contentType) {
            $contentType = self::overlayGetenv('HTTP_CONTENT_TYPE');
        }
        if (false === $contentType || '' === $contentType) {
            throw new \RequestParseBodyException('RequestParseBodyException: Missing Content-Type header');
        }

        $body = self::overlayGetenv('REQUEST_BODY');
        if (false === $body) {
            $body = '';
        }
        if ('' === $body) {
            return;
        }

        $mediaType = self::contentTypeMediaType($contentType);
        if ('application/x-www-form-urlencoded' === $mediaType) {
            ParseStrNativeJitHelper::parseIntoNative($postPtr, $body);

            return;
        }
        if (str_starts_with($mediaType, 'multipart/form-data')) {
            MultipartNativeJitHelper::populateMultipartIntoNative($postPtr, $filesPtr, $contentType, $body);

            return;
        }

        throw new \RequestParseBodyException('RequestParseBodyException: Unsupported Content-Type');
    }

    private static function overlayGetenv(string $name): string|false
    {
        return GetenvJitHelper::getenv($name, 0);
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
}
