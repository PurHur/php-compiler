--TEST--
stdlib gd imagearc/imagefilledarc + IMG_ARC_* (#20437, ext/gd/gd.c)
--FILE--
<?php
foreach (['imagearc', 'imagefilledarc'] as $f) {
    echo $f, '=', (int) function_exists($f), "\n";
}
foreach (['IMG_ARC_PIE', 'IMG_ARC_CHORD', 'IMG_ARC_NOFILL', 'IMG_ARC_EDGED'] as $c) {
    echo $c, '=', (int) constant($c), "\n";
}

$im = imagecreatetruecolor(40, 40);
imagealphablending($im, false);
$bg = imagecolorallocate($im, 0, 0, 0);
$fg = imagecolorallocate($im, 255, 255, 255);
imagefilledrectangle($im, 0, 0, 39, 39, $bg);
imagearc($im, 20, 20, 30, 30, 0, 90, $fg);
$arcInk = 0;
for ($y = 0; $y < 40; ++$y) {
    for ($x = 0; $x < 40; ++$x) {
        if ((imagecolorat($im, $x, $y) & 0xFFFFFF) === ($fg & 0xFFFFFF)) {
            ++$arcInk;
        }
    }
}
echo 'arc_ink=', (int) ($arcInk > 0), "\n";
echo 'arc_right=', (int) ((imagecolorat($im, 35, 20) & 0xFFFFFF) !== 0), "\n";
echo 'arc_center_clear=', (int) ((imagecolorat($im, 20, 20) & 0xFFFFFF) === 0), "\n";

$pie = imagecreatetruecolor(40, 40);
imagealphablending($pie, false);
imagefilledrectangle($pie, 0, 0, 39, 39, $bg);
imagefilledarc($pie, 20, 20, 30, 30, 0, 90, $fg, IMG_ARC_PIE);
echo 'pie_center=', (int) ((imagecolorat($pie, 20, 20) & 0xFFFFFF) !== 0), "\n";
echo 'pie_wedge=', (int) ((imagecolorat($pie, 28, 22) & 0xFFFFFF) !== 0), "\n";

$chord = imagecreatetruecolor(40, 40);
imagealphablending($chord, false);
imagefilledrectangle($chord, 0, 0, 39, 39, $bg);
imagefilledarc($chord, 20, 20, 30, 30, 0, 90, $fg, IMG_ARC_CHORD | IMG_ARC_NOFILL | IMG_ARC_EDGED);
echo 'chord_edge=', (int) ((imagecolorat($chord, 20, 20) & 0xFFFFFF) !== 0), "\n";
?>
--EXPECT--
imagearc=1
imagefilledarc=1
IMG_ARC_PIE=0
IMG_ARC_CHORD=1
IMG_ARC_NOFILL=2
IMG_ARC_EDGED=4
arc_ink=1
arc_right=1
arc_center_clear=1
pie_center=1
pie_wedge=1
chord_edge=1
