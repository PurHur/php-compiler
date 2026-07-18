<?php
/**
 * Repro for #20415 — imageistruecolor / imagetruecolortopalette / imagepalettetotruecolor.
 */
foreach (['imageistruecolor', 'imagetruecolortopalette', 'imagepalettetotruecolor'] as $f) {
    echo $f, '=', function_exists($f) ? 'yes' : 'no', PHP_EOL;
}
$im = imagecreatetruecolor(8, 8);
echo 'tc=', imageistruecolor($im) ? '1' : '0', PHP_EOL;
imagetruecolortopalette($im, false, 16);
echo 'pal=', imageistruecolor($im) ? '1' : '0', PHP_EOL;
imagepalettetotruecolor($im);
echo 'back=', imageistruecolor($im) ? '1' : '0', PHP_EOL;
