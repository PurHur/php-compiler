<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Lowered into JIT/AOT modules for phpinfo()/phpcredits() (#9256, php-in-PHP).
 *
 * SSOT: {@see VmInfo::renderPhpinfoHtml()} / {@see VmInfo::renderPhpcreditsHtml()}; echo → ob_*.
 * php-src: ext/standard/info.c — phpinfo(), phpcredits()
 */
final class PhpinfoJitHelper
{
    public static function phpinfo(int $flags): bool
    {
        echo VmInfo::renderPhpinfoHtml($flags);

        return true;
    }

    public static function phpcredits(int $flags): void
    {
        echo VmInfo::renderPhpcreditsHtml($flags);
    }
}
