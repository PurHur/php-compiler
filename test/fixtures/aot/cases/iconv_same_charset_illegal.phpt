--TEST--
AOT iconv() same-charset illegal UTF-8 returns false (#25167)
--FILE--
<?php
$r = @iconv('UTF-8', 'UTF-8', "a\x80b");
echo ($r === false) ? "false\n" : ("hex=" . bin2hex($r) . "\n");
echo bin2hex((string) iconv('UTF-8', 'UTF-8//IGNORE', "a\x80b")), "\n";
--EXPECT--
false
6162
