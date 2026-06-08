<?php

declare(strict_types=1);

namespace PHPCompiler\ext\libxml;

/**
 * libxml error level constants (php-src ext/libxml/libxml.c; issue #6058).
 */
final class LibxmlConstants
{
    public const LIBXML_ERR_NONE = 0;

    public const LIBXML_ERR_WARNING = 1;

    public const LIBXML_ERR_ERROR = 2;

    public const LIBXML_ERR_FATAL = 3;
}
