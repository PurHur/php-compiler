--TEST--
preg_quote() str:/delimiter: named parameters (#15279, ext/pcre/pcre.stub.php)
--FILE--
<?php
declare(strict_types=1);

echo preg_quote(str: 'a.b', delimiter: '/'), "\n";
--EXPECT--
a\.b
