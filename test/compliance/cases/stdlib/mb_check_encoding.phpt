--TEST--
stdlib mb_check_encoding() UTF-8 validity (VM)
--FILE--
<?php
echo (int) function_exists('mb_check_encoding'), "\n";
var_export(mb_check_encoding('café', 'UTF-8'));
echo "\n";
var_export(mb_check_encoding("\xFF", 'UTF-8'));
echo "\n";
var_export(mb_check_encoding(['ok', 'é'], 'UTF-8'));
echo "\n";
var_export(mb_check_encoding(['ok', "\xFF"], 'UTF-8'));
echo "\n";
try {
    mb_check_encoding('x', 'NOPE');
    echo "no error\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
1
true
false
true
false
mb_check_encoding(): Argument #2 ($encoding) must be a valid encoding, "NOPE" given
