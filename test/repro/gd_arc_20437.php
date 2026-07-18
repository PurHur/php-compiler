<?php
declare(strict_types=1);

/**
 * Repro for #20437 — imagearc / imagefilledarc registration + stroke/fill styles.
 */
foreach (['imagearc', 'imagefilledarc', 'imageline'] as $f) {
    echo $f, '=', function_exists($f) ? 'yes' : 'no', PHP_EOL;
}

foreach (['IMG_ARC_PIE', 'IMG_ARC_CHORD', 'IMG_ARC_NOFILL', 'IMG_ARC_EDGED'] as $c) {
    echo $c, '=', defined($c) ? (string) constant($c) : 'undef', PHP_EOL;
}

$im = imagecreatetruecolor(40, 40);
imagealphablending($im, false);
$bg = imagecolorallocate($im, 0, 0, 0);
$fg = imagecolorallocate($im, 255, 255, 255);
imagefilledrectangle($im, 0, 0, 39, 39, $bg);
// Quarter circle outline from 0° (+x) to 90° (+y, clockwise in libgd).
imagearc($im, 20, 20, 30, 30, 0, 90, $fg);
$arcInk = 0;
for ($y = 0; $y < 40; ++$y) {
    for ($x = 0; $x < 40; ++$x) {
        if ((imagecolorat($im, $x, $y) & 0xFFFFFF) === ($fg & 0xFFFFFF)) {
            ++$arcInk;
        }
    }
}
echo 'arc_ink=', $arcInk > 0 ? 'yes' : 'no', PHP_EOL;
// Point on +x radius should be inked; center should stay background for outline.
echo 'arc_right=', ((imagecolorat($im, 35, 20) & 0xFFFFFF) !== 0) ? 'yes' : 'no', PHP_EOL;
echo 'arc_center_clear=', ((imagecolorat($im, 20, 20) & 0xFFFFFF) === 0) ? 'yes' : 'no', PHP_EOL;

$pie = imagecreatetruecolor(40, 40);
imagealphablending($pie, false);
imagefilledrectangle($pie, 0, 0, 39, 39, $bg);
imagefilledarc($pie, 20, 20, 30, 30, 0, 90, $fg, IMG_ARC_PIE);
echo 'pie_center=', ((imagecolorat($pie, 20, 20) & 0xFFFFFF) !== 0) ? 'yes' : 'no', PHP_EOL;
echo 'pie_wedge=', ((imagecolorat($pie, 28, 22) & 0xFFFFFF) !== 0) ? 'yes' : 'no', PHP_EOL;

$chord = imagecreatetruecolor(40, 40);
imagealphablending($chord, false);
imagefilledrectangle($chord, 0, 0, 39, 39, $bg);
imagefilledarc($chord, 20, 20, 30, 30, 0, 90, $fg, IMG_ARC_CHORD | IMG_ARC_NOFILL | IMG_ARC_EDGED);
echo 'chord_edge=', ((imagecolorat($chord, 20, 20) & 0xFFFFFF) !== 0) ? 'yes' : 'no', PHP_EOL;
