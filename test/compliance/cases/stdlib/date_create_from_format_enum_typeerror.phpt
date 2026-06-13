--TEST--
stdlib date_create_from_format — enum case operands must TypeError (#6172, php-src-strict)
--FILE--
<?php
enum E: string { case A = '2024-06-05'; }

try {
    date_create_from_format('Y-m-d', E::A);
    echo "no-error\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

try {
    date_parse(E::A);
    echo "no-error\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
date_create_from_format(): Argument #2 ($datetime) must be of type string, E given
date_parse(): Argument #1 ($date) must be of type string, E given
