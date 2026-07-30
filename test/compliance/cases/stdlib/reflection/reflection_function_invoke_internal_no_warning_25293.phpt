--TEST--
ReflectionFunction invoke/invokeArgs on internal functions — no strictTypes warning (#25293)
--FILE--
<?php
$r = new ReflectionFunction('strlen');
echo $r->invoke('abc'), "\n";
echo $r->invokeArgs(['abc']), "\n";
echo "ok\n";
?>
--EXPECT--
3
3
ok
