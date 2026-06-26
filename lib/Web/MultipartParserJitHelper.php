<?php

declare(strict_types=1);

namespace PHPCompiler\Web;

use PHPCompiler\VM\HashTable;

/**
 * multipart/form-data populate for compiled JIT/AOT modules (#9394, php-in-PHP).
 *
 * SSOT: {@see MultipartParser}
 * php-src: main/rfc1867.c — php_parse_multipart_form_data
 */
final class MultipartParserJitHelper
{
    public static function populateTables(
        HashTable $post,
        HashTable $files,
        string $contentType,
        string $body
    ): void {
        if ('' === $contentType) {
            MultipartParser::populate($post, $files, $body);

            return;
        }

        $prevContentType = getenv('CONTENT_TYPE');
        $prevHttpContentType = getenv('HTTP_CONTENT_TYPE');
        putenv('CONTENT_TYPE='.$contentType);
        putenv('HTTP_CONTENT_TYPE='.$contentType);
        try {
            MultipartParser::populate($post, $files, $body);
        } finally {
            if (false === $prevContentType || '' === $prevContentType) {
                putenv('CONTENT_TYPE');
            } else {
                putenv('CONTENT_TYPE='.$prevContentType);
            }
            if (false === $prevHttpContentType || '' === $prevHttpContentType) {
                putenv('HTTP_CONTENT_TYPE');
            } else {
                putenv('HTTP_CONTENT_TYPE='.$prevHttpContentType);
            }
        }
    }
}
