--TEST--
stdlib preg_match() pattern:/subject:/matches: named parameters (#10035, ext/pcre/pcre.stub.php)
--FILE--
<?php
declare(strict_types=1);

$m = [];
preg_match(pattern: '/a/', subject: 'abc', matches: $m);
echo $m[0], "\n";
--EXPECT--
a
