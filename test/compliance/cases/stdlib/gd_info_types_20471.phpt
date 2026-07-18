--TEST--
stdlib gd gd_info/imagetypes (#20471, ext/gd/gd.c)
--FILE--
<?php
foreach (['gd_info', 'imagetypes'] as $n) {
    echo $n, '=', (int) function_exists($n), "\n";
}
$mask = imagetypes();
echo 'mask=', $mask, "\n";
echo 'png=', (int) (0 !== ($mask & IMG_PNG)), "\n";
echo 'jpeg=', (int) (0 !== ($mask & IMG_JPEG)), "\n";
echo 'gif=', (int) (0 !== ($mask & IMG_GIF)), "\n";
echo 'webp=', (int) (0 !== ($mask & IMG_WEBP)), "\n";
echo 'bmp=', (int) (0 !== ($mask & IMG_BMP)), "\n";
echo 'avif=', (int) (0 !== ($mask & IMG_AVIF)), "\n";
echo 'wbmp=', (int) (0 !== ($mask & IMG_WBMP)), "\n";
$info = gd_info();
echo 'version=', (int) isset($info['GD Version']), "\n";
echo 'png_support=', (int) (!empty($info['PNG Support'])), "\n";
echo 'wbmp_support=', (int) (!empty($info['WBMP Support'])), "\n";
echo 'ok', "\n";
?>
--EXPECT--
gd_info=1
imagetypes=1
mask=359
png=1
jpeg=1
gif=1
webp=1
bmp=1
avif=1
wbmp=0
version=1
png_support=1
wbmp_support=0
ok
