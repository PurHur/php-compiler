--TEST--
stdlib string builtins JIT — null TypeError under declare(strict_types=1) (#18802, #18803, #18804, ext/standard/string.c)
--FILE--
<?php
declare(strict_types=1);

foreach ([
    'str_word_count' => static fn () => str_word_count(null),
    'hex2bin' => static fn () => hex2bin(null),
    'chunk_split' => static fn () => chunk_split(null),
    'str_split' => static fn () => str_split(null),
    'strrev' => static fn () => strrev(null),
    'convert_uudecode' => static fn () => convert_uudecode(null),
    'timezone_name_from_abbr' => static fn () => timezone_name_from_abbr(null),
    'str_pad' => static fn () => str_pad(null, 5),
] as $label => $factory) {
    try {
        $factory();
        echo "$label: uncaught\n";
    } catch (TypeError $e) {
        echo "$label: TypeError\n";
    }
}
?>
--EXPECT--
str_word_count: TypeError
hex2bin: TypeError
chunk_split: TypeError
str_split: TypeError
strrev: TypeError
convert_uudecode: TypeError
timezone_name_from_abbr: TypeError
str_pad: TypeError
