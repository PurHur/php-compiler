--TEST--
stdlib error_reporting() inline E_ALL & ~MASK bitmask (#15391)
--FILE--
<?php
$old = error_reporting();
error_reporting(E_ALL & ~E_NOTICE);
echo error_reporting() === (E_ALL & ~E_NOTICE) ? "mask1\n" : "mask1-fail\n";
error_reporting($old);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
echo error_reporting() === (E_ALL & ~E_DEPRECATED & ~E_STRICT) ? "mask2\n" : "mask2-fail\n";
error_reporting($old);
$m = E_ALL & ~E_NOTICE;
error_reporting($m);
echo error_reporting() === $m ? "var\n" : "var-fail\n";
error_reporting($old);
ini_set('error_reporting', (string) (E_ALL & ~E_NOTICE));
echo error_reporting() === (E_ALL & ~E_NOTICE) ? "ini\n" : "ini-fail\n";
error_reporting($old);
--EXPECT--
mask1
mask2
var
ini
