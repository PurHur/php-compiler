--TEST--
base64_encode/urlencode/urldecode/rawurl* named string: arguments (JIT, issue #23257)
--FILE--
<?php
echo base64_encode(string: 'ab'), PHP_EOL;
echo urlencode(string: 'a b'), PHP_EOL;
echo urldecode(string: 'a+b'), PHP_EOL;
echo rawurlencode(string: 'a b'), PHP_EOL;
echo rawurldecode(string: 'a%20b'), PHP_EOL;
--EXPECT--
YWI=
a+b
a b
a%20b
a b
