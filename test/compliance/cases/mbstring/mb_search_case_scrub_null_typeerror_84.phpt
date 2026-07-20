--TEST--
mb_strwidth/search/case/scrub null $string TypeError on 8.4 profile (#21061, ext/mbstring/mbstring.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--RUNFILE--
mb_search_case_scrub_null_typeerror_84.php
--EXPECT--
mb_strwidth: mb_strwidth(): Argument #1 ($string) must be of type string, null given
mb_strstr: mb_strstr(): Argument #1 ($haystack) must be of type string, null given
mb_stristr: mb_stristr(): Argument #1 ($haystack) must be of type string, null given
mb_strrchr: mb_strrchr(): Argument #1 ($haystack) must be of type string, null given
mb_stripos: mb_stripos(): Argument #1 ($haystack) must be of type string, null given
mb_strripos: mb_strripos(): Argument #1 ($haystack) must be of type string, null given
mb_strrpos: mb_strrpos(): Argument #1 ($haystack) must be of type string, null given
mb_convert_case: mb_convert_case(): Argument #1 ($string) must be of type string, null given
mb_scrub: mb_scrub(): Argument #1 ($string) must be of type string, null given
mb_str_split: mb_str_split(): Argument #1 ($string) must be of type string, null given
mb_encode_mimeheader: mb_encode_mimeheader(): Argument #1 ($str) must be of type string, null given
mb_decode_mimeheader: mb_decode_mimeheader(): Argument #1 ($string) must be of type string, null given
mb_convert_kana: mb_convert_kana(): Argument #1 ($string) must be of type string, null given
