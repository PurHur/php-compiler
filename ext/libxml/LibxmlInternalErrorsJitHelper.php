<?php

declare(strict_types=1);

namespace PHPCompiler\ext\libxml;

/**
 * libxml_use_internal_errors() static flag for VM + NestedJIT AOT (#28659, php-in-PHP).
 *
 * VM SSOT delegates here via {@see VmLibxml}.
 * php-src: ext/libxml/libxml.c — PHP_FUNCTION(libxml_use_internal_errors)
 */
final class LibxmlInternalErrorsJitHelper
{
    private static bool $useInternalErrors = false;

    /**
     * @param bool $hasNew when false (omitted/null arg), only return the previous flag
     *
     * @return bool previous use_internal_errors flag
     */
    public static function exchange(bool $hasNew, bool $newValue): bool
    {
        $previous = self::$useInternalErrors;
        if ($hasNew) {
            self::$useInternalErrors = $newValue;
        }

        return $previous;
    }

    public static function using(): bool
    {
        return self::$useInternalErrors;
    }
}
