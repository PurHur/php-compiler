--TEST--
stdlib mktime()/gmmktime() null hour — int timestamp not TypeError (#18370, ext/standard/datetime.c)
--FILE--
<?php
echo is_int(mktime(null)) ? "mktime_int\n" : "mktime_bad\n";
echo is_int(gmmktime(null)) ? "gmmktime_int\n" : "gmmktime_bad\n";
?>
--EXPECT--
mktime_int
gmmktime_int
