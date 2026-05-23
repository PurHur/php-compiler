--TEST--
AOT: isset on missing $_SERVER key does not warn (#747, #273)
--ENV--
QUERY_STRING=
--FILE--
<?php
declare(strict_types=1);
$key = isset($_SERVER['PATH_INFO']) ? $_SERVER['PATH_INFO'] : '';
echo $key === '' ? "ok\n" : "bad\n";
--EXPECT--
ok
