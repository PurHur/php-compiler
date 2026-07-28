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
        foreach (MbstringConstants::registeredConstants() as $name => $value) {
            $var = new VM\Variable();
            $var->int($value);
            $runtime->vmContext->defineConstant($name, $var);
        }
    }

    public function getFunctions(): array
    {
        return [
            new mb_check_encoding(),
            new mb_list_encodings(),
            new mb_strlen(),
            new mb_chr(),
            new mb_ord(),
            new mb_str_split(),
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
            new mb_detect_encoding(),
            new mb_convert_variables(),
            new mb_stripos(),
            new mb_strripos(),
            new mb_strrpos(),
            new mb_strrchr(),
            new mb_strrichr(),
            new mb_strstr(),
            new mb_stristr(),
            ...(CompilerVersion::supportsMbTrimFunctions() ? [
                new mb_trim(),
                new mb_ltrim(),
                new mb_rtrim(),
            ] : []),
            ...(CompilerVersion::supportsMbUcfirstLcfirst() ? [
                new mb_ucfirst(),
                new mb_lcfirst(),
            ] : []),
            ...(CompilerVersion::supportsMbUcwords() ? [
                new mb_ucwords(),
            ] : []),
            new mb_scrub(),
            new mb_encode_numericentity(),
            new mb_decode_numericentity(),
            new mb_encode_mimeheader(),
            new mb_decode_mimeheader(),
            new mb_send_mail(),
            new mb_http_output(),
            new mb_get_info(),
            new mb_output_handler(),
            new mb_internal_encoding(),
            new mb_language(),
            new mb_http_input(),
            new mb_parse_str(),
            new mb_detect_order(),
            new mb_substitute_character(),
            new mb_preferred_mime_name(),
            new mb_encoding_aliases(),
            new mb_convert_kana(),
            new mb_split(),
            new mb_ereg(),
            new mb_eregi(),
            new mb_ereg_replace(),
            new mb_eregi_replace(),
            new mb_ereg_replace_callback(),
            new mb_ereg_match(),
            new mb_ereg_search(),
            new mb_ereg_search_pos(),
            new mb_ereg_search_regs(),
            new mb_ereg_search_init(),
            new mb_ereg_search_getregs(),
            new mb_ereg_search_getpos(),
            new mb_ereg_search_setpos(),
            new mb_regex_encoding(),
            new mb_regex_set_options(),
        ];
    }
}
