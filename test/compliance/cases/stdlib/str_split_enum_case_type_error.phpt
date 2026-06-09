--TEST--
stdlib str_split() — TypeError for enum case operand (ext/standard/string.c)
--FILE--
<?php
enum S: string { case X = 'ab'; }
try {
    str_split(S::X);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo "TypeError\n";
}
--EXPECT--
TypeError
