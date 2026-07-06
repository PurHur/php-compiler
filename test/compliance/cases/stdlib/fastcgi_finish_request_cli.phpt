--TEST--
stdlib fastcgi_finish_request() returns false on CLI (issue #3466)
--FILE--
<?php
echo function_exists('fastcgi_finish_request') ? 'exists' : 'missing', "\n";
echo "before\n";
$ok = fastcgi_finish_request();
var_export($ok);
echo "\nafter\n";
--EXPECT--
exists
before
false
after
