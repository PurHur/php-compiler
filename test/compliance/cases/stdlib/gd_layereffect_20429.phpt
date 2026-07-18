--TEST--
stdlib gd imagelayereffect + IMG_EFFECT_* (#20429, ext/gd/gd.c)
--FILE--
<?php
foreach (['imagelayereffect'] as $fn) {
    echo $fn, '=', (int) function_exists($fn), "\n";
}
foreach (['IMG_EFFECT_REPLACE', 'IMG_EFFECT_ALPHABLEND', 'IMG_EFFECT_NORMAL', 'IMG_EFFECT_OVERLAY', 'IMG_EFFECT_MULTIPLY'] as $c) {
    echo $c, '=', (int) defined($c), "\n";
}

$im = imagecreatetruecolor(2, 2);
imagealphablending($im, false);
$bg = imagecolorallocate($im, 128, 128, 128);
imagefilledrectangle($im, 0, 0, 1, 1, $bg);

$overlay = imagecolorallocatealpha($im, 255, 0, 0, 63);
imagelayereffect($im, IMG_EFFECT_REPLACE);
imagesetpixel($im, 0, 0, $overlay);
$replacePx = imagecolorat($im, 0, 0);

imagelayereffect($im, IMG_EFFECT_OVERLAY);
imagesetpixel($im, 1, 0, $overlay);
$overlayPx = imagecolorat($im, 1, 0);

imagelayereffect($im, IMG_EFFECT_MULTIPLY);
imagesetpixel($im, 0, 1, $overlay);
$multiplyPx = imagecolorat($im, 0, 1);

echo 'replace_ne_overlay=', (int) ($replacePx !== $overlayPx), "\n";
echo 'replace_ne_multiply=', (int) ($replacePx !== $multiplyPx), "\n";
echo 'set=', (int) imagelayereffect($im, IMG_EFFECT_NORMAL), "\n";
echo 'vals=', IMG_EFFECT_REPLACE, ',', IMG_EFFECT_ALPHABLEND, ',', IMG_EFFECT_NORMAL, ',', IMG_EFFECT_OVERLAY, ',', IMG_EFFECT_MULTIPLY, "\n";
?>
--EXPECT--
imagelayereffect=1
IMG_EFFECT_REPLACE=1
IMG_EFFECT_ALPHABLEND=1
IMG_EFFECT_NORMAL=1
IMG_EFFECT_OVERLAY=1
IMG_EFFECT_MULTIPLY=1
replace_ne_overlay=1
replace_ne_multiply=1
set=1
vals=0,1,2,3,4
