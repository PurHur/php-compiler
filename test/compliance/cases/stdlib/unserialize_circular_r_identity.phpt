--TEST--
stdlib unserialize() circular array R: restores identity (#22652, ext/standard/var_unserializer.re)
--FILE--
<?php
$a = [];
$a[0] = &$a;
$u = unserialize(serialize($a));
echo ($u === $u[0]) ? "roundtrip_ok\n" : "roundtrip_fail\n";
$u2 = unserialize('a:1:{i:0;a:1:{i:0;R:2;}}');
echo ($u2 === $u2[0]) ? "blob_ok\n" : "blob_fail\n";
$u2[0]['m'] = 1;
echo isset($u2[0][0]['m']) ? "cycle_ok\n" : "cycle_fail\n";
?>
--EXPECT--
roundtrip_ok
blob_ok
cycle_ok
