--TEST--
stdlib curl_escape() — backed enum case operand TypeError (#6351, #20493, php-src-strict)
--ENV--
PHP_COMPILER_ENABLE_CURL=1
--FILE--
<?php
enum E: string { case A = 'x'; }
$ch = curl_init();
try {
    curl_escape($ch, E::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    curl_escape(E::A, 'x');
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
curl_escape(): Argument #2 ($string) must be of type string, E given
curl_escape(): Argument #1 ($handle) must be of type CurlHandle, E given
