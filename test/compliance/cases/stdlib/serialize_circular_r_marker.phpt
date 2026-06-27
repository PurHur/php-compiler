--TEST--
stdlib serialize() self-ref array — R: marker index (#12825, ext/standard/var.c)
--FILE--
<?php
$a = [];
$a[0] = &$a;
$blob = serialize($a);
echo $blob === 'a:1:{i:0;a:1:{i:0;R:2;}}' ? "ok\n" : "fail\n";
?>
--EXPECT--
ok
