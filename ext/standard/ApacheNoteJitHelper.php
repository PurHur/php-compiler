<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * apache_note()/apache_get_version() JIT/AOT unavailable paths (#6276, php-in-PHP).
 *
 * php-src: ext/standard/head.c
 */
final class ApacheNoteJitHelper
{
    public static function noteUnavailable(): string|false
    {
        compiler_language_warning(VmApache::noteUnavailableMessage());

        return false;
    }

    public static function versionUnavailable(): string|false
    {
        compiler_language_warning(VmApache::versionUnavailableMessage());

        return false;
    }
}
