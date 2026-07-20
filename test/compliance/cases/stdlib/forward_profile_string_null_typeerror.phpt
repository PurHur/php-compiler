--TEST--
stdlib Z_PARAM_STR builtins — null soft-coerce mix on 8.4 (#19161/#19309/#19319/#21420)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL & ~E_DEPRECATED & ~E_WARNING);
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
        $result = $factory();
        echo "$label: ";
        var_export($result);
        echo "\n";
    } catch (TypeError $e) {
        echo "$label: ", $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
str_word_count: 0
hex2bin: ''
chunk_split: '
'
str_split: array (
)
strrev: ''
convert_uudecode: false
timezone_name_from_abbr: false
