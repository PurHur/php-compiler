--TEST--
stdlib: web_int/web_string/web_bool JIT (issue #157)
--FILE--
<?php
declare(strict_types=1);
$get = ['page' => 'abc', 'name' => '  Bob  ', 'flag' => 'on', 'bad' => 'maybe'];
echo web_int($get, 'page', 1), "\n";
echo web_string($get, 'name', ''), "\n";
echo web_bool($get, 'flag', false), "\n";
echo web_bool($get, 'bad', true), "\n";
echo web_bool($get, 'missing', true), "\n";
--EXPECT--
1
Bob
1
1
1
