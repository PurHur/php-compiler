--TEST--
stdlib urlencode()/rawurlencode() null TypeError on 8.4 forward profile (#18733 #18912, ext/standard/url.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach (['urlencode', 'rawurlencode'] as $fn) {
    try {
        $fn(null);
        echo "$fn: uncaught\n";
    } catch (TypeError $e) {
        echo $fn.': '.$e->getMessage()."\n";
    }
}
--EXPECT--
urlencode: urlencode(): Argument #1 ($string) must be of type string, null given
rawurlencode: rawurlencode(): Argument #1 ($string) must be of type string, null given
