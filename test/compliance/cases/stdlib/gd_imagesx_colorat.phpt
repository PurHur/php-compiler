--TEST--
stdlib imagesx()/imagesy()/imagecolorat() on truecolor GdImage (#6217, ext/gd/gd.c)
--FILE--
<?php
declare(strict_types=1);

echo 'exists=', (int) function_exists('imagesx'), "\n";
$im = imagecreatetruecolor(3, 2);
echo imagesx($im), 'x', imagesy($im), "\n";
$white = imagecolorallocate($im, 255, 255, 255);
imagefill($im, 0, 0, $white);
echo imagecolorat($im, 0, 0), "\n";
?>
--EXPECT--
exists=1
3x2
16777215
