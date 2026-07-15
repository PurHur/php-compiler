--TEST--
stdlib imageflip() vertical on truecolor GdImage (#6380, ext/gd/gd.c)
--FILE--
<?php
declare(strict_types=1);

echo 'exists=', (int) function_exists('imageflip'), "\n";
$im = imagecreatetruecolor(1, 2);
$top = imagecolorallocate($im, 255, 0, 0);
$bottom = imagecolorallocate($im, 0, 255, 0);
imagesetpixel($im, 0, 0, $top);
imagesetpixel($im, 0, 1, $bottom);
echo 'before=', imagecolorat($im, 0, 0), ',', imagecolorat($im, 0, 1), "\n";
echo 'ok=', (int) imageflip($im, IMG_FLIP_VERTICAL), "\n";
echo 'after=', imagecolorat($im, 0, 0), ',', imagecolorat($im, 0, 1), "\n";
?>
--EXPECT--
exists=1
before=16711680,65280
ok=1
after=65280,16711680
