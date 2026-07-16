--TEST--
stdlib urlencode()/rawurlencode()/urldecode()/rawurldecode() null TypeError on 8.4 forward profile JIT (#19272, ext/standard/url.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
foreach (['urlencode', 'rawurlencode', 'urldecode', 'rawurldecode'] as $fn) {
    try {
        $fn(null);
        echo "$fn: uncaught\n";
    } catch (TypeError $e) {
        echo $fn.': '.$e->getMessage()."\n";
    }
}
?>
--EXPECT--
urlencode: urlencode(): Argument #1 ($string) must be of type string, null given
rawurlencode: rawurlencode(): Argument #1 ($string) must be of type string, null given
urldecode: urldecode(): Argument #1 ($string) must be of type string, null given
rawurldecode: rawurldecode(): Argument #1 ($string) must be of type string, null given
