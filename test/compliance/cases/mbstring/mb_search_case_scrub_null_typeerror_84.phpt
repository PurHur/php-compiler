--TEST--
mb_strwidth/search/case/scrub null $string on 8.4 profile (#21061, #21313, #21516, ext/mbstring/mbstring.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--RUNFILE--
mb_search_case_scrub_null_typeerror_84.php
--EXPECT--
mb_strwidth: OK
mb_strstr: OK
mb_stristr: OK
mb_strrchr: OK
mb_stripos: OK
mb_strripos: OK
mb_strrpos: OK
mb_convert_case: OK
mb_strtoupper: OK
mb_scrub: OK
mb_str_split: mb_str_split(): Argument #1 ($string) must be of type string, null given
mb_encode_mimeheader: OK
mb_decode_mimeheader: mb_decode_mimeheader(): Argument #1 ($string) must be of type string, null given
mb_convert_kana: OK
