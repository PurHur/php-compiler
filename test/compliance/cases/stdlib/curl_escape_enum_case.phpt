--TEST--
stdlib curl_escape() — backed enum case operand TypeError (#6351, php-src-strict)
--FILE--
<?php
enum E: string { case A = 'x'; }
try {
    curl_escape(E::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
curl_escape(): Argument #1 ($string) must be of type string, E given
