--TEST--
preg_split() pattern:/subject: named parameters (#10060, ext/pcre/pcre.stub.php)
--FILE--
<?php
declare(strict_types=1);

var_export(preg_split(pattern: '/ /', subject: 'a b'));
echo "\n";
--EXPECT--
array (
  0 => 'a',
  1 => 'b',
)
