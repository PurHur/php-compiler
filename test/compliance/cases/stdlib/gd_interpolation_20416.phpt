--TEST--
stdlib gd imagesetinterpolation/imagegetinterpolation + IMG_* (#20416, ext/gd/gd.c)
--FILE--
<?php
foreach (['imagesetinterpolation', 'imagegetinterpolation'] as $fn) {
    echo $fn, '=', (int) function_exists($fn), "\n";
}
echo 'IMG_BILINEAR_FIXED=', (int) defined('IMG_BILINEAR_FIXED'), "\n";
echo 'IMG_NEAREST_NEIGHBOUR=', (int) defined('IMG_NEAREST_NEIGHBOUR'), "\n";
echo 'IMG_BICUBIC=', (int) defined('IMG_BICUBIC'), "\n";
echo 'IMG_TRIANGLE=', (int) defined('IMG_TRIANGLE'), "\n";
echo 'IMG_WEIGHTED4=', (int) defined('IMG_WEIGHTED4'), "\n";

$im = imagecreatetruecolor(4, 4);
echo 'default=', imagegetinterpolation($im), ' expect=', IMG_BILINEAR_FIXED, "\n";
echo 'set_nn=', (int) imagesetinterpolation($im, IMG_NEAREST_NEIGHBOUR), "\n";
echo 'get_nn=', imagegetinterpolation($im), ' expect=', IMG_NEAREST_NEIGHBOUR, "\n";
echo 'set_default=', (int) imagesetinterpolation($im, IMG_DEFAULT), "\n";
echo 'get_after_default=', imagegetinterpolation($im), ' expect=', IMG_BILINEAR_FIXED, "\n";
echo 'set_minus1=', (int) imagesetinterpolation($im, -1), "\n";
echo 'get_minus1=', imagegetinterpolation($im), ' expect=', IMG_BILINEAR_FIXED, "\n";
echo 'set_bad=', (int) imagesetinterpolation($im, 999), "\n";
echo 'get_unchanged=', imagegetinterpolation($im), ' expect=', IMG_BILINEAR_FIXED, "\n";
?>
--EXPECT--
imagesetinterpolation=1
imagegetinterpolation=1
IMG_BILINEAR_FIXED=1
IMG_NEAREST_NEIGHBOUR=1
IMG_BICUBIC=1
IMG_TRIANGLE=1
IMG_WEIGHTED4=1
default=3 expect=3
set_nn=1
get_nn=16 expect=16
set_default=1
get_after_default=3 expect=3
set_minus1=1
get_minus1=3 expect=3
set_bad=0
get_unchanged=3 expect=3
