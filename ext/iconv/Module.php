<?php

declare(strict_types=1);

namespace PHPCompiler\ext\iconv;

use PHPCompiler\ModuleAbstract;

/**
 * iconv extension module entry (php-src ext/iconv/iconv.c; issue #6251).
 */
class Module extends ModuleAbstract
{
    public function getFunctions(): array
    {
        return [
            new iconv(),
            new iconv_strlen(),
            new iconv_strpos(),
            new iconv_substr(),
            new iconv_strrpos(),
        ];
    }
}
