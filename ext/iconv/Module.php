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
        // registeredConstants() includes ICONV_IMPL / ICONV_VERSION strings (#24053).
        foreach (IconvConstants::registeredConstants() as $name => $value) {
            $var = new VM\Variable();
            if (\is_string($value)) {
                $var->string($value);
            } else {
                $var->int((int) $value);
            }
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
