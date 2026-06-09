--TEST--
AOT: mb_convert_case() — backed enum case TypeError (#7014)
--FILE--
<?php
enum Es: string { case B = 'hi'; }
try {
    mb_convert_case(Es::B, MB_CASE_UPPER, 'UTF-8');
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
mb_convert_case(): Argument #1 ($string) must be of type string, Es given
