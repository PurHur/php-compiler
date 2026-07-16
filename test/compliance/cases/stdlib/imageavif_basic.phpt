--TEST--
stdlib imageavif()/imagecreatefromavif() 1x1 round-trip (#6378, ext/gd/gd.c)
--FILE--
<?php
declare(strict_types=1);

echo 'exists=', (int) function_exists('imageavif'), ',', (int) function_exists('imagecreatefromavif'), "\n";
$im = imagecreatetruecolor(1, 1);
$blue = imagecolorallocate($im, 10, 20, 200);
imagesetpixel($im, 0, 0, $blue);
ob_start();
imageavif($im);
$avif = ob_get_clean();
echo 'bytes=', (int) (strlen($avif) > 20 && strpos($avif, 'ftyp') !== false), "\n";
$im2 = imagecreatefromavif('data://application/octet-stream;base64,'.base64_encode($avif));
echo 'class=', is_object($im2) ? get_class($im2) : 'no', "\n";
echo 'px=', imagecolorat($im2, 0, 0), "\n";
?>
--EXPECT--
exists=1,1
bytes=1
class=GdImage
px=660680
