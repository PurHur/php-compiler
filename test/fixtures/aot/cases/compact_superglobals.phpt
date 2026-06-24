--TEST--
AOT: compact() superglobals (#11237)
--FILE--
<?php
declare(strict_types=1);
$keys = array_keys(compact('_SERVER', '_GET', '_POST'));
sort($keys);
var_export($keys);
echo "\n";
--EXPECT--
array (
  0 => '_GET',
  1 => '_POST',
  2 => '_SERVER',
)
