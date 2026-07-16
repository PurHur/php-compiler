--TEST--
stdlib imagewebp()/imagecreatefromwebp() 1x1 round-trip (#6378, ext/gd/gd.c)
--FILE--
<?php
declare(strict_types=1);

echo 'exists=', (int) function_exists('imagewebp'), ',', (int) function_exists('imagecreatefromwebp'), "\n";
$im = imagecreatetruecolor(1, 1);
$red = imagecolorallocate($im, 200, 10, 10);
imagesetpixel($im, 0, 0, $red);
ob_start();
imagewebp($im);
$webp = ob_get_clean();
echo 'bytes=', (int) (strlen($webp) > 20 && strncmp($webp, 'RIFF', 4) === 0), "\n";
$im2 = imagecreatefromwebp('data://application/octet-stream;base64,'.base64_encode($webp));
echo 'class=', is_object($im2) ? get_class($im2) : 'no', "\n";
echo 'px=', imagecolorat($im2, 0, 0), "\n";
?>
--EXPECT--
exists=1,1
bytes=1
class=GdImage
px=13109770
