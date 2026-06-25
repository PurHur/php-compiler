<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * data: stream wrapper decode for file_get_contents() (#11433, ext/standard/php_data_wrapper.c).
 *
 * @see https://github.com/php/php-src/blob/master/ext/standard/php_data_wrapper.c
 */
final class VmDataUri
{
    public static function isDataUri(string $path): bool
    {
        return str_starts_with($path, 'data:');
    }

    /**
     * @return string|false payload bytes; false when URI is malformed
     */
    public static function decode(string $path): string|false
    {
        if (!self::isDataUri($path)) {
            return false;
        }

        $rest = substr($path, 5);
        if (str_starts_with($rest, '//')) {
            $rest = substr($rest, 2);
        }

        $comma = strpos($rest, ',');
        if (false === $comma) {
            return false;
        }

        $meta = substr($rest, 0, $comma);
        $data = substr($rest, $comma + 1);
        $base64 = false;
        if ('' !== $meta) {
            $parts = explode(';', $meta);
            foreach ($parts as $part) {
                if ('' === $part) {
                    continue;
                }
                if (0 === strcasecmp($part, 'base64')) {
                    $base64 = true;
                }
            }
        }

        if ($base64) {
            $decoded = base64_decode($data, true);

            return false === $decoded ? false : $decoded;
        }

        return rawurldecode($data);
    }
}
