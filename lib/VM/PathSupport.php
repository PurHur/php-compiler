<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Path validation shared by include/require and stream builtins (Zend zend_stream.c).
 */
final class PathSupport
{
    public const EMPTY_PATH_VALUE_ERROR_MESSAGE = 'Path cannot be empty';

    public static function isEmptyPath(string $path): bool
    {
        return '' === $path;
    }
}
