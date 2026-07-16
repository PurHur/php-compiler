<?php

declare(strict_types=1);

namespace PHPCompiler\ext\iconv;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;
use PHPCompiler\VM;

/**
 * iconv extension module entry (php-src ext/iconv/iconv.c; issue #6251, #6364).
 */
class Module extends ModuleAbstract
{
    public function init(Runtime $runtime): void
    {
        parent::init($runtime);
        foreach ([
            'ICONV_MIME_DECODE_STRICT' => IconvConstants::MIME_DECODE_STRICT,
            'ICONV_MIME_DECODE_CONTINUE_ON_ERROR' => IconvConstants::MIME_DECODE_CONTINUE_ON_ERROR,
        ] as $name => $value) {
            $var = new VM\Variable();
            $var->int($value);
            $runtime->vmContext->defineConstant($name, $var);
        }
    }

    public function getFunctions(): array
    {
        return [
            new iconv(),
            new iconv_strlen(),
            new iconv_strpos(),
            new iconv_substr(),
            new iconv_strrpos(),
            new iconv_get_encoding(),
            new iconv_set_encoding(),
            new iconv_mime_decode(),
            new iconv_mime_decode_headers(),
            new iconv_mime_encode(),
        ];
    }
}
