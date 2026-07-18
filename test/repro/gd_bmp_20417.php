<?php
/**
 * Repro for #20417 — imagecreatefrombmp / imagebmp.
 */
foreach (['imagecreatefrombmp', 'imagebmp'] as $f) {
    echo $f, '=', function_exists($f) ? 'yes' : 'no', PHP_EOL;
}
$im = imagecreatetruecolor(4, 4);
$tmp = sys_get_temp_dir() . '/phpc_bmp_repro_' . getmypid() . '.bmp';
imagebmp($im, $tmp);
$loaded = imagecreatefrombmp($tmp);
@unlink($tmp);
echo get_debug_type($loaded), ' ', imagesx($loaded), 'x', imagesy($loaded), PHP_EOL;
