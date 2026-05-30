<?php

declare(strict_types=1);

namespace PHPCompiler\Web\FastCgi;

/**
 * Apply FastCGI PARAMS to the process CGI environment (issue #173).
 */
final class Environment
{
    /**
     * @param array<string, string> $params
     */
    public static function apply(array $params): void
    {
        foreach ($params as $key => $value) {
            if (!is_string($key) || '' === $key) {
                continue;
            }
            putenv($key.'='.$value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }

    /**
     * @param array<string, string> $params
     */
    public static function applyRequestBody(array $params, string $stdinBody): void
    {
        if ('' !== $stdinBody) {
            putenv('REQUEST_BODY='.$stdinBody);
            $_ENV['REQUEST_BODY'] = $stdinBody;
            $_SERVER['REQUEST_BODY'] = $stdinBody;
            $contentLength = (string) strlen($stdinBody);
            putenv('CONTENT_LENGTH='.$contentLength);
            $_ENV['CONTENT_LENGTH'] = $contentLength;
            $_SERVER['CONTENT_LENGTH'] = $contentLength;
            if (!isset($params['REQUEST_METHOD']) || 'POST' !== strtoupper($params['REQUEST_METHOD'])) {
                putenv('REQUEST_METHOD=POST');
                $_ENV['REQUEST_METHOD'] = 'POST';
                $_SERVER['REQUEST_METHOD'] = 'POST';
            }

            return;
        }

        $contentLength = $params['CONTENT_LENGTH'] ?? '0';
        putenv('CONTENT_LENGTH='.$contentLength);
        $_ENV['CONTENT_LENGTH'] = $contentLength;
        $_SERVER['CONTENT_LENGTH'] = $contentLength;
    }
}
