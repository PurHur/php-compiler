--TEST--
idn_to_ascii/utf8 empty domain → intl_get_error_message suffixes U_ILLEGAL_ARGUMENT_ERROR (#23546)
--SKIPIF--
<?php
if (!function_exists('idn_to_ascii') || !function_exists('intl_get_error_message')) {
    die('skip idn/intl error builtins not advertised');
}
?>
--FILE--
<?php
declare(strict_types=1);

@idn_to_ascii('');
echo intl_get_error_code(), '|', intl_get_error_message(), "\n";

@idn_to_utf8('');
echo intl_get_error_code(), '|', intl_get_error_message(), "\n";

@idn_to_ascii('x', IDNA_DEFAULT, 999);
echo intl_get_error_code(), '|', intl_get_error_message(), "\n";
?>
--EXPECT--
1|idn_to_ascii: empty domain name: U_ILLEGAL_ARGUMENT_ERROR
1|idn_to_utf8: empty domain name: U_ILLEGAL_ARGUMENT_ERROR
1|idn_to_ascii: invalid variant, must be INTL_IDNA_VARIANT_UTS46: U_ILLEGAL_ARGUMENT_ERROR
