--TEST--
stdlib Z_PARAM_STR null — mixed soft-null/TypeError on 8.4 (#19309/#21180, ext/standard/string.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
$cases = [
    'chunk_split' => fn () => chunk_split(null),
    'explode' => fn () => explode(',', null),
    'addslashes' => fn () => addslashes(null),
    'str_rot13' => fn () => str_rot13(null),
    'count_chars' => fn () => count_chars(null, 3),
    'str_word_count' => fn () => str_word_count(null),
    'crc32' => fn () => crc32(null),
    'base_convert' => fn () => base_convert(null, 10, 16),
    'convert_uuencode' => fn () => convert_uuencode(null),
    'quotemeta' => fn () => quotemeta(null),
];
foreach ($cases as $name => $fn) {
    try {
        $r = $fn();
        echo "{$name}: uncaught ", var_export($r, true), "\n";
    } catch (TypeError $e) {
        echo $e->getMessage(), "\n";
    }
}
echo var_export(addslashes(''), true), "\n";
?>
--EXPECT--
chunk_split(): Argument #1 ($string) must be of type string, null given
explode: uncaught array (
  0 => '',
)
addslashes: uncaught ''
str_rot13(): Argument #1 ($string) must be of type string, null given
count_chars(): Argument #1 ($string) must be of type string, null given
str_word_count(): Argument #1 ($string) must be of type string, null given
crc32: uncaught 0
base_convert(): Argument #1 ($num) must be of type string, null given
convert_uuencode(): Argument #1 ($string) must be of type string, null given
quotemeta: uncaught ''
''
