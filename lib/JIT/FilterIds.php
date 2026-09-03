<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

/**
 * FILTER_* ids needed by lib/JIT Builtin lowering (#36204).
 *
 * Values match php-src ext/filter/php_filter.h / filter_private.h.
 * Full constant surface stays in {@see \PHPCompiler\ext\filter\VmFilter}.
 */
final class FilterIds
{
    public const FILTER_VALIDATE_INT = 0x0101;
    public const FILTER_VALIDATE_BOOLEAN = 0x0102;
    public const FILTER_VALIDATE_FLOAT = 0x0103;
    public const FILTER_VALIDATE_URL = 0x0111;
    public const FILTER_VALIDATE_EMAIL = 0x0112;
    public const FILTER_VALIDATE_IP = 0x0113;
    public const FILTER_VALIDATE_MAC = 0x0114;
    public const FILTER_VALIDATE_DOMAIN = 0x0115;
    public const FILTER_DEFAULT = 0x0204;

    private function __construct()
    {
    }
}
