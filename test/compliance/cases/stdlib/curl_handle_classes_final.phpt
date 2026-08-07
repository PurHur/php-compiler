--TEST--
CurlHandle/CurlMultiHandle/CurlShareHandle ReflectionClass::isFinal() (php-src ext/curl/curl.stub.php; #28371)
--ENV--
PHP_COMPILER_ENABLE_CURL=1
--FILE--
<?php
foreach (['CurlHandle', 'CurlMultiHandle', 'CurlShareHandle'] as $c) {
    echo $c, '=', (new ReflectionClass($c))->isFinal() ? "yes\n" : "no\n";
}
?>
--EXPECT--
CurlHandle=yes
CurlMultiHandle=yes
CurlShareHandle=yes
