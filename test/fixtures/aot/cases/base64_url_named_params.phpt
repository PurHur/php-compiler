--TEST--
AOT: base64_encode/urlencode/urldecode/rawurl* named string: arguments (#23257)
--FILE--
<?php
echo base64_encode(string: 'ab'), "\n";
echo urlencode(string: 'a b'), "\n";
echo urldecode(string: 'a+b'), "\n";
echo rawurlencode(string: 'a b'), "\n";
echo rawurldecode(string: 'a%20b'), "\n";
--EXPECT--
YWI=
a+b
a b
a%20b
a b
