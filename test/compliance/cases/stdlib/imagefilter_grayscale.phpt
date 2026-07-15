--TEST--
stdlib imagefilter() grayscale on truecolor GdImage (#6380, ext/gd/gd.c)
--FILE--
<?php
declare(strict_types=1);

echo 'exists=', (int) function_exists('imagefilter'), "\n";
$im = imagecreatetruecolor(1, 1);
$blue = imagecolorallocate($im, 0, 0, 255);
imagesetpixel($im, 0, 0, $blue);
echo 'before=', imagecolorat($im, 0, 0), "\n";
echo 'ok=', (int) imagefilter($im, IMG_FILTER_GRAYSCALE), "\n";
echo 'after=', imagecolorat($im, 0, 0), "\n";
?>
--EXPECT--
exists=1
before=255
ok=1
after=1907997
