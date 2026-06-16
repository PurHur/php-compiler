--TEST--
Language: enum case fetch materializes enum object not backing scalar (#8725, #8746, zend_enum.c)
--FILE--
<?php
enum ES: string { case A = 'a'; }
enum Pure { case A; }

$x = ES::A;
echo 'backed var debug_type=', get_debug_type($x), "\n";
echo 'backed var is_object=', is_object($x) ? 'yes' : 'no', "\n";
echo 'backed direct debug_type=', get_debug_type(ES::A), "\n";
echo 'backed direct is_object=', is_object(ES::A) ? 'yes' : 'no', "\n";
echo 'backed serialize=', serialize(ES::A), "\n";
try {
    str_contains(ES::A, 'a');
    echo "backed str_contains: no TypeError\n";
} catch (TypeError $e) {
    echo 'backed str_contains: TypeError', "\n";
}

$p = Pure::A;
echo 'pure var debug_type=', get_debug_type($p), "\n";
echo 'pure var is_object=', is_object($p) ? 'yes' : 'no', "\n";
echo 'pure serialize=', serialize(Pure::A), "\n";
--EXPECT--
backed var debug_type=ES
backed var is_object=yes
backed direct debug_type=ES
backed direct is_object=yes
backed serialize=E:4:"ES:A";
backed str_contains: TypeError
pure var debug_type=Pure
pure var is_object=yes
pure serialize=E:6:"Pure:A";
