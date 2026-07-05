--TEST--
stdlib getimagesize() readable non-image file — false with no warning (#16434, ext/standard/image.c)
--FILE--
<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');
$tmp = tempnam(sys_get_temp_dir(), 'img');
file_put_contents($tmp, 'not an image');
$result = @getimagesize($tmp);
$last = error_get_last();
unlink($tmp);
var_export($result);
echo "\n";
var_export($last);
echo "\n";
echo (false === $result && null === $last) ? 'ok' : 'fail';
echo "\n";
?>
--EXPECT--
false
NULL
ok
