--TEST--
stdlib ini_get() after ini_set() returns active local value (#11835, ext/standard/ini.c)
--FILE--
<?php
ini_set('display_errors', '0');
echo ini_get('display_errors') === '0' ? "ok\n" : "fail\n";
ini_set('display_errors', 'Off');
echo ini_get('display_errors') === 'Off' ? "off\n" : "off-fail\n";
ini_restore('display_errors');
echo ini_get('display_errors') === '' ? "restored\n" : "restore-fail\n";
--EXPECT--
ok
off
restored
