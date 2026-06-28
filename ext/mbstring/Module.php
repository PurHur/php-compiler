<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\CompilerVersion;
use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;
use PHPCompiler\VM;

/**
 * mbstring extension module entry (php-src ext/mbstring/mbstring.c; issue #5695).
 */
class Module extends ModuleAbstract
{
    public function init(Runtime $runtime): void
    {
        parent::init($runtime);
        foreach ([
            'MB_CASE_UPPER' => MbstringConstants::MB_CASE_UPPER,
            'MB_CASE_LOWER' => MbstringConstants::MB_CASE_LOWER,
            'MB_CASE_TITLE' => MbstringConstants::MB_CASE_TITLE,
        ] as $name => $value) {
            $var = new VM\Variable();
            $var->int($value);
            $runtime->vmContext->defineConstant($name, $var);
        }
    }

    public function getFunctions(): array
    {
        return [
            new mb_check_encoding(),
            new mb_strlen(),
            new mb_strwidth(),
            new mb_strimwidth(),
            ...(CompilerVersion::supportsMbStrPad() ? [new mb_str_pad()] : []),
            new mb_substr(),
            new mb_strcut(),
            new mb_substr_count(),
            new mb_strpos(),
            new mb_strtolower(),
            new mb_strtoupper(),
            new mb_convert_case(),
            new mb_convert_encoding(),
            new mb_stripos(),
            new mb_strrpos(),
            new mb_strrichr(),
            ...(CompilerVersion::supportsMbTrimFunctions() ? [
                new mb_trim(),
                new mb_ltrim(),
                new mb_rtrim(),
            ] : []),
            new mb_scrub(),
            new mb_encode_numericentity(),
            new mb_decode_numericentity(),
            new mb_encode_mimeheader(),
            new mb_decode_mimeheader(),
            new mb_http_output(),
            new mb_detect_order(),
            new mb_substitute_character(),
            new mb_preferred_mime_name(),
            new mb_encoding_aliases(),
        ];
    }
}
