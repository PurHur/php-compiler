<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * request_parse_body() JIT/AOT nested helper — writes into native hashtable pointers.
 */
final class RequestParseBodyJitHelper
{
    public static function parseIntoNative(int $postPtr, int $filesPtr, ?array $options = null): void
    {
        [$post, $files] = RequestParseBodyEngine::parseFromEnvironment($options);
        ParseStrNativeJitHelper::mergeIntoNative($postPtr, $post);
        ParseStrNativeJitHelper::mergeIntoNative($filesPtr, $files);
    }
}

