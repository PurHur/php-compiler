<?php

declare(strict_types=1);

namespace {
    // Host PHP 8.2 does not ship RequestParseBodyException; define a thin polyfill so
    // VM builtins can throw it while still mapping to the VM builtin class.
    if (!class_exists('RequestParseBodyException', false)) {
        class RequestParseBodyException extends \Exception
        {
        }
    }
}

namespace PHPCompiler\ext\standard {

use PHPCompiler\Web\UploadTemp;

/**
 * PHP 8.4+ request_parse_body() engine (php-src: ext/standard/http.c).
 *
 * Shared by VM builtin execute() and JIT/AOT nested helper.
 */
final class RequestParseBodyEngine
{
    private static bool $consumed = false;

    /** @var list<string> */
    private const OPTION_KEYS = [
        'max_file_uploads',
        'max_input_vars',
        'max_multipart_body_parts',
        'post_max_size',
        'upload_max_filesize',
    ];

    /**
     * @param array<string, int|string>|null $options
     *
     * @return array{0: array, 1: array} [$_POST, $_FILES]
     */
    public static function parseFromEnvironment(?array $options = null): array
    {
        self::validateOptions($options);

        // Zend: body is a one-shot stream; subsequent parses return empty.
        if (self::$consumed) {
            return [[], []];
        }
        self::$consumed = true;

        $mediaType = self::contentTypeMediaType();
        if ('' === $mediaType) {
            throw new \RequestParseBodyException('RequestParseBodyException: Missing Content-Type header');
        }

        $body = self::readRequestBody();
        if ('' === $body) {
            return [[], []];
        }

        if ('application/x-www-form-urlencoded' === $mediaType) {
            return [ParseStrEngine::parse($body), []];
        }
        if (str_starts_with($mediaType, 'multipart/form-data')) {
            return self::parseMultipartFromEnvironment($body);
        }

        throw new \RequestParseBodyException('RequestParseBodyException: Unsupported Content-Type');
    }

    /**
     * @param array<string, int|string>|null $options
     */
    private static function validateOptions(?array $options): void
    {
        if (null === $options) {
            return;
        }
        foreach ($options as $key => $value) {
            if (!is_string($key) || !in_array($key, self::OPTION_KEYS, true)) {
                throw new \ValueError('request_parse_body(): Argument #1 ($options) contains invalid keys');
            }
            if (!is_int($value) && !is_string($value)) {
                throw new \ValueError('request_parse_body(): Argument #1 ($options) contains invalid values');
            }
        }
    }

    /**
     * @return array{0: array, 1: array}
     */
    private static function parseMultipartFromEnvironment(string $body): array
    {
        $boundary = self::extractBoundaryFromEnvironment();
        if (null === $boundary) {
            throw new \RequestParseBodyException('RequestParseBodyException: Invalid multipart boundary');
        }

        $post = [];
        $files = [];

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
                $files[$fieldName] = self::buildFileEntry($filename, $rawHeaders, $content);
                continue;
            }

            $params = [];
            parse_str($fieldName.'='.$content, $params);
            $post = self::mergeArrays($post, $params);
        }

        return [$post, $files];
    }

    /**
     * @return array{name: string, type: string, tmp_name: string, error: int, size: int}
     */
    private static function buildFileEntry(string $filename, string $rawHeaders, string $content): array
    {
        $tmp = UploadTemp::createTempFile();
        if (false === $tmp) {
            return [
                'name' => $filename,
                'type' => 'application/octet-stream',
                'tmp_name' => '',
                'error' => 1,
                'size' => 0,
            ];
        }
        if (false === file_put_contents($tmp, $content)) {
            @unlink($tmp);

            return [
                'name' => $filename,
                'type' => 'application/octet-stream',
                'tmp_name' => '',
                'error' => 1,
                'size' => 0,
            ];
        }

        $partType = self::headerValue($rawHeaders, 'Content-Type');

        return [
            'name' => $filename,
            'type' => null !== $partType && '' !== $partType ? $partType : 'application/octet-stream',
            'tmp_name' => $tmp,
            'error' => 0,
            'size' => strlen($content),
        ];
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

    private static function overlayGetenv(string $name): string|false
    {
        $jit = GetenvJitHelper::getenv($name, 0);
        if (false !== $jit) {
            return $jit;
        }

        return VmEnv::getenv($name);
    }

    private static function extractBoundaryFromEnvironment(): ?string
    {
        $contentType = self::overlayGetenv('CONTENT_TYPE');
        if (false === $contentType || '' === $contentType) {
            $contentType = self::overlayGetenv('HTTP_CONTENT_TYPE');
        }
        if (false === $contentType || '' === $contentType) {
            return null;
        }
        if (!preg_match('/boundary\s*=\s*(?:"([^"]+)"|([^\s;]+))/i', $contentType, $matches)) {
            return null;
        }

        return '' !== $matches[1] ? $matches[1] : $matches[2];
    }

    private static function contentTypeMediaType(): string
    {
        $contentType = self::overlayGetenv('CONTENT_TYPE');
        if (false === $contentType || '' === $contentType) {
            $contentType = self::overlayGetenv('HTTP_CONTENT_TYPE');
        }
        if (false === $contentType || '' === $contentType) {
            return '';
        }
        $contentType = strtolower(trim($contentType));
        $semi = strpos($contentType, ';');
        if (false !== $semi) {
            $contentType = substr($contentType, 0, $semi);
        }

        return trim($contentType);
    }

    private static function readRequestBody(): string
    {
        $fromFile = self::overlayGetenv('REQUEST_BODY_FILE');
        if (false !== $fromFile && '' !== $fromFile && is_readable($fromFile)) {
            $contents = file_get_contents($fromFile);

            return false === $contents ? '' : $contents;
        }
        $fromEnv = self::overlayGetenv('REQUEST_BODY');

        return false === $fromEnv ? '' : $fromEnv;
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

    /**
     * Merge parse_str-style arrays without reindexing numeric keys.
     *
     * @param array $base
     * @param array $next
     */
    private static function mergeArrays(array $base, array $next): array
    {
        foreach ($next as $k => $v) {
            if (is_array($v) && isset($base[$k]) && is_array($base[$k])) {
                $base[$k] = self::mergeArrays($base[$k], $v);
            } else {
                $base[$k] = $v;
            }
        }

        return $base;
    }
}

}

