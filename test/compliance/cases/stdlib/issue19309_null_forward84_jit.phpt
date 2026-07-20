--TEST--
stdlib Z_PARAM_STR null — mixed soft-null/TypeError on 8.4 JIT (#19309/#21180/#21280, ext/standard/string.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
error_reporting(E_ALL & ~E_DEPRECATED);
$cases = [
    'chunk_split' => [fn () => chunk_split(null), "\r\n"],
    'explode' => [fn () => explode(',', null), ['']],
    'addslashes' => [fn () => addslashes(null), null],
    'str_rot13' => [fn () => str_rot13(null), ''],
    'count_chars' => [fn () => count_chars(null, 3), null],
    'str_word_count' => [fn () => str_word_count(null), null],
    'crc32' => [fn () => crc32(null), 0],
    'base_convert' => [fn () => base_convert(null, 10, 16), '0'],
    'convert_uuencode' => [fn () => convert_uuencode(null), "`\n"],
    'quotemeta' => [fn () => quotemeta(null), ''],
];
foreach ($cases as $name => [$fn, $expect]) {
    try {
        $r = $fn();
        if (null === $expect) {
            echo "{$name}: uncaught\n";
        } else {
            echo $name, ' ', ($r === $expect ? 'OK' : 'BAD'), "\n";
        }
    } catch (TypeError $e) {
        echo $name, " TypeError\n";
    }
}
echo addslashes('') === '' ? "addslashes_empty OK\n" : "addslashes_empty BAD\n";
?>
--EXPECT--
chunk_split OK
explode OK
addslashes TypeError
str_rot13 OK
count_chars TypeError
str_word_count TypeError
crc32 OK
base_convert OK
convert_uuencode OK
quotemeta OK
addslashes_empty OK
