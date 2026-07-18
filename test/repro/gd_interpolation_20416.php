<?php
/**
 * Repro for #20416 — imagesetinterpolation / imagegetinterpolation + IMG_*.
 */
foreach (['imagesetinterpolation', 'imagegetinterpolation'] as $f) {
    echo $f, '=', function_exists($f) ? 'yes' : 'no', PHP_EOL;
}
echo 'IMG_BILINEAR_FIXED=', defined('IMG_BILINEAR_FIXED') ? 'yes' : 'no', PHP_EOL;
$im = imagecreatetruecolor(4, 4);
echo 'default=', imagegetinterpolation($im), PHP_EOL;
imagesetinterpolation($im, IMG_NEAREST_NEIGHBOUR);
echo 'nn=', imagegetinterpolation($im), PHP_EOL;
