--TEST--
mb_strlen() string:/encoding: named parameters (#10044, ext/mbstring/mbstring.stub.php)
--FILE--
<?php
declare(strict_types=1);

echo mb_strlen(string: 'é', encoding: 'UTF-8'), "\n";
echo mb_strlen(string: 'abc', encoding: 'ASCII'), "\n";
--EXPECT--
1
3
