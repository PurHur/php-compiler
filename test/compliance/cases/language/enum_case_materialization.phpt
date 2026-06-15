--TEST--
Language: backed enum case fetch materializes enum object not backing scalar (#8725, zend_enum.c)
--FILE--
<?php
enum ES: string { case A = 'a'; }

$x = ES::A;
echo 'var debug_type=', get_debug_type($x), "\n";
echo 'var is_object=', is_object($x) ? 'yes' : 'no', "\n";
echo 'direct debug_type=', get_debug_type(ES::A), "\n";
echo 'direct is_object=', is_object(ES::A) ? 'yes' : 'no', "\n";
echo 'serialize=', serialize(ES::A), "\n";
try {
    str_contains(ES::A, 'a');
    echo "str_contains: no TypeError\n";
} catch (TypeError $e) {
    echo 'str_contains: TypeError', "\n";
}
--EXPECT--
var debug_type=ES
var is_object=yes
direct debug_type=ES
direct is_object=yes
serialize=E:4:"ES:A";
str_contains: TypeError
