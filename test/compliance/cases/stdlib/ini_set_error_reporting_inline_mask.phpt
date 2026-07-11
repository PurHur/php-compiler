--TEST--
stdlib ini_set('error_reporting') inline E_ALL & ~MASK bitmask (#15460)
--FILE--
<?php
$old = error_reporting();
ini_set('error_reporting', (string) (E_ALL & ~E_NOTICE));
echo error_reporting() === (E_ALL & ~E_NOTICE) ? "ini-inline\n" : "ini-inline-fail\n";
error_reporting($old);
$m = E_ALL & ~E_NOTICE;
ini_set('error_reporting', (string) $m);
echo error_reporting() === $m ? "ini-var\n" : "ini-var-fail\n";
error_reporting($old);
--EXPECT--
ini-inline
ini-var
