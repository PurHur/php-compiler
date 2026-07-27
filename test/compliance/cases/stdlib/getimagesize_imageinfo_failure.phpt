--TEST--
stdlib getimagesize*() failure initializes $imageinfo to [] — Zend parity (#23816, ext/standard/image.c)
--FILE--
<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');

$info = null;
$result = @getimagesize(__FILE__, $info);
echo 'getimagesize result=' . var_export($result, true) . ' info=' . var_export($info, true) . "\n";

$info = null;
$result = @getimagesizefromstring('not-an-image', $info);
echo 'getimagesizefromstring result=' . var_export($result, true) . ' info=' . var_export($info, true) . "\n";
?>
--EXPECT--
getimagesize result=false info=array (
)
getimagesizefromstring result=false info=array (
)
