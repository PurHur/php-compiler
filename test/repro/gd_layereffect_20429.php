<?php
declare(strict_types=1);

/**
 * Repro for #20429 — imagelayereffect() + IMG_EFFECT_* constants.
 */
echo 'imagelayereffect=', function_exists('imagelayereffect') ? 'yes' : 'no', PHP_EOL;
foreach (['IMG_EFFECT_REPLACE', 'IMG_EFFECT_ALPHABLEND', 'IMG_EFFECT_NORMAL', 'IMG_EFFECT_OVERLAY', 'IMG_EFFECT_MULTIPLY'] as $c) {
    echo $c, '=', defined($c) ? 'yes' : 'no', PHP_EOL;
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

echo 'replace_ne_overlay=', ($replacePx !== $overlayPx) ? 'yes' : 'no', PHP_EOL;
echo 'replace_ne_multiply=', ($replacePx !== $multiplyPx) ? 'yes' : 'no', PHP_EOL;
echo 'set=', imagelayereffect($im, IMG_EFFECT_NORMAL) ? 'yes' : 'no', PHP_EOL;
echo 'REPLACE=', IMG_EFFECT_REPLACE, ' ALPHABLEND=', IMG_EFFECT_ALPHABLEND, ' NORMAL=', IMG_EFFECT_NORMAL, ' OVERLAY=', IMG_EFFECT_OVERLAY, ' MULTIPLY=', IMG_EFFECT_MULTIPLY, PHP_EOL;
