--TEST--
stdlib glob() invalid flag bitmask — Warning + false (#16970, ext/standard/dir.c)
--FILE--
<?php
echo glob('*', 99999) === false ? "ok\n" : "fail\n";
--EXPECT--
ok
