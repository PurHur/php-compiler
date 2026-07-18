<?php
declare(strict_types=1);

/**
 * Repro for #20430 — imageresolution() get/set DPI.
 */
echo 'imageresolution=', function_exists('imageresolution') ? 'yes' : 'no', PHP_EOL;
$im = imagecreatetruecolor(8, 8);
$def = imageresolution($im);
echo 'default=', var_export($def, true), PHP_EOL;
echo 'set=', imageresolution($im, 300, 300) ? 'yes' : 'no', PHP_EOL;
echo 'after=', var_export(imageresolution($im), true), PHP_EOL;
echo 'set_one=', imageresolution($im, 150) ? 'yes' : 'no', PHP_EOL;
echo 'after_one=', var_export(imageresolution($im), true), PHP_EOL;
try {
    imageresolution($im, -1);
    echo "neg_ok\n";
} catch (ValueError $e) {
    echo "neg_ve\n";
}
