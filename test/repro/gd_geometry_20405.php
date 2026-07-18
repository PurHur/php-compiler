<?php

declare(strict_types=1);

/**
 * Repro for #20405 — GD geometry batch (imagerotate/imagescale/imageconvolution/imagecopyresized).
 */
foreach (['imagerotate', 'imagescale', 'imageconvolution', 'imagecopyresized'] as $f) {
    echo $f, '=', function_exists($f) ? '1' : '0', PHP_EOL;
}
$im = imagecreatetruecolor(8, 8);
$r = imagerotate($im, 90, 0);
echo get_debug_type($r), PHP_EOL;
