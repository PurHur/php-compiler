--TEST--
stdlib bin2hex()/urlencode()/rawurlencode() — null coerces to empty string on default profile JIT (#18912, ext/standard/string.c, url.c)
--JIT--
--FILE--
<?php
foreach (['bin2hex', 'urlencode', 'rawurlencode'] as $fn) {
    var_dump($fn(null));
}
--EXPECT--
string(0) ""
string(0) ""
string(0) ""
