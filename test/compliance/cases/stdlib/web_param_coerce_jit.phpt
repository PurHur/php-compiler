--TEST--
stdlib: web_int/web_string JIT (issue #157)
--FILE--
<?php
declare(strict_types=1);
$get = ['page' => 'abc', 'name' => '  Bob  '];
echo web_int($get, 'page', 1), "\n";
echo web_string($get, 'name', ''), "\n";
--EXPECT--
1
Bob
