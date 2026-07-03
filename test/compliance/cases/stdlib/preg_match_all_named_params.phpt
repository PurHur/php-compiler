--TEST--
preg_match_all() pattern:/subject: named parameters (#15281, ext/pcre/pcre.stub.php)
--FILE--
<?php
declare(strict_types=1);

$m = [];
preg_match_all(pattern: '/a/', subject: 'aba', matches: $m);
echo count($m[0]), "\n";
--EXPECT--
2
