--TEST--
stdlib fopen() invalid mode on php://memory — resource sentinel not false (#13401)
--FILE--
<?php
$h = @fopen('php://memory', 'invalid');
echo ($h === false ? 'false' : 'not-false'), "\n";
echo gettype($h), "\n";
echo var_export($h, true), "\n";
echo (int) is_resource($h), "\n";
$h2 = @fopen('/no/such/path-'.uniqid('', true), 'r');
echo ($h2 === false ? 'missing-false' : 'missing-not-false'), "\n";
--EXPECT--
not-false
resource
NULL
1
missing-false
