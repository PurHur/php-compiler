--TEST--
stdlib compact() superglobals — compact('_SERVER') includes CGI arrays (#11237, basic_functions.c)
--FILE--
<?php
declare(strict_types=1);
$keys = array_keys(compact('_SERVER', '_GET', '_POST'));
sort($keys);
var_export($keys);
echo "\n";
echo is_array($_SERVER) && is_array($_GET) && is_array($_POST) ? "ok\n" : "fail\n";
--EXPECT--
array (
  0 => '_GET',
  1 => '_POST',
  2 => '_SERVER',
)
ok
