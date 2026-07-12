--TEST--
stdlib imagecreatefromstring() 1x1 PNG decode + imagepng round-trip (#6215, ext/gd/gd.c)
--FILE--
<?php
declare(strict_types=1);

$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
echo 'exists=', (int) function_exists('imagecreatefromstring'), "\n";
$im = imagecreatefromstring($png);
echo 'class=', get_class($im), "\n";
ob_start();
imagepng($im);
echo strlen(ob_get_clean()) > 8 ? "ok\n" : "fail\n";
?>
--EXPECT--
exists=1
class=GdImage
ok
