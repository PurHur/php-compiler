--TEST--
stdlib Z_PARAM_STR builtins — null TypeError on 8.4 forward profile (#18778, #18798, #18799, #18797)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach ([
    'str_word_count' => static fn () => str_word_count(null),
    'hex2bin' => static fn () => hex2bin(null),
    'chunk_split' => static fn () => chunk_split(null),
    'str_split' => static fn () => str_split(null),
    'strrev' => static fn () => strrev(null),
    'convert_uudecode' => static fn () => convert_uudecode(null),
    'timezone_name_from_abbr' => static fn () => timezone_name_from_abbr(null),
] as $label => $factory) {
    try {
        $factory();
        echo "$label: uncaught\n";
    } catch (TypeError $e) {
        echo $label.': '.$e->getMessage()."\n";
    }
}
--EXPECT--
str_word_count: str_word_count(): Argument #1 ($string) must be of type string, null given
hex2bin: hex2bin(): Argument #1 ($string) must be of type string, null given
chunk_split: chunk_split(): Argument #1 ($string) must be of type string, null given
str_split: str_split(): Argument #1 ($string) must be of type string, null given
strrev: strrev(): Argument #1 ($string) must be of type string, null given
convert_uudecode: convert_uudecode(): Argument #1 ($string) must be of type string, null given
timezone_name_from_abbr: timezone_name_from_abbr(): Argument #1 ($abbr) must be of type string, null given
