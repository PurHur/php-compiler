--TEST--
stdlib str_getcsv() multi-byte CSV option ValueError under PROFILE=8.4 — JIT (#24148, file.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--DESCRIPTION--
Same as str_getcsv_single_char_84.phpt; JIT path via JitCsvArg::validateStrGetcsvCall.
--RUNFILE--
str_getcsv_single_char_84.php
--EXPECT--
sep2 ValueError: str_getcsv(): Argument #2 ($separator) must be a single character
enc2 ValueError: str_getcsv(): Argument #3 ($enclosure) must be a single character
esc2 ValueError: str_getcsv(): Argument #4 ($escape) must be empty or a single character
esc_empty_ok OK ["a","b"]
