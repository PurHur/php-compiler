--TEST--
stdlib urlencode()/rawurlencode() null TypeError JIT (#18733, ext/standard/url.c)
--JIT--
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
