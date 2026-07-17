--TEST--
stdlib sodium_increment()/add()/compare() (#20081)
--SKIPIF--
<?php if (!extension_loaded('sodium')) { die('skip ext/sodium not loaded on reference host'); } ?>
--FILE--
<?php
if (!extension_loaded('sodium')
    || !function_exists('sodium_increment')
    || !function_exists('sodium_add')
    || !function_exists('sodium_compare')
) {
    echo "missing\n";
    exit(0);
}
$a = "\x00\x00\x00\x00";
sodium_increment($a);
echo bin2hex($a) === '01000000' ? "inc\n" : "inc_fail\n";
$b = "\x01\x00\x00\x00";
sodium_add($b, "\x02\x00\x00\x00");
echo bin2hex($b) === '03000000' ? "add\n" : "add_fail\n";
echo sodium_compare('ab', 'ab') === 0 ? "eq\n" : "eq_fail\n";
echo sodium_compare("\x01\x00", "\x02\x00") < 0 ? "lt\n" : "lt_fail\n";
echo sodium_compare("\x02\x00", "\x01\x00") > 0 ? "gt\n" : "gt_fail\n";
try {
    sodium_compare('a', 'ab');
    echo "len_fail\n";
} catch (\SodiumException $e) {
    echo "len_ok\n";
}
try {
    $x = 1;
    sodium_increment($x);
    echo "type_fail\n";
} catch (\SodiumException $e) {
    echo "type_ok\n";
}
--EXPECT--
inc
add
eq
lt
gt
len_ok
type_ok
